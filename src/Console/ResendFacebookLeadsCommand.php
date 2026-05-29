<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\Facebook\FacebookLeadService;
use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Retries the Facebook CAPI 'Lead' event for leads that should have fired one
 * but never did. The normal path dispatches SendLeadToFacebookJob after a lead
 * is sent to Duo; if the worker was down, the job was lost, or it exhausted its
 * retries, the lead is left fb_eligible with fb_synced_at still null and no
 * trace on the row. This command finds those and re-sends synchronously so the
 * operator sees the result of each attempt immediately.
 *
 * Sends in-process rather than re-queuing so failures surface here instead of
 * silently in the worker log. Meta rejects Lead events older than 7 days, so
 * very old leads will fail — use --since to scope a backfill.
 */
class ResendFacebookLeadsCommand extends Command
{
    protected $signature = 'leads:resend-facebook
        {--id=* : Resend specific lead IDs (bypasses the eligibility/status filters)}
        {--site= : Restrict to a single site (defaults to all sites)}
        {--since= : Only consider leads created within this window (e.g. 24h, 7d). Meta rejects events older than 7 days.}
        {--limit=100 : Maximum leads to process in one run}
        {--dry-run : List candidate leads without sending}';

    protected $description = 'Retry the Facebook CAPI Lead event for eligible leads that never synced.';

    public function handle(FacebookLeadService $facebook): int
    {
        $query = Lead::query()->withoutGlobalScopes();

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('fb_eligible', true)
                ->whereNull('fb_synced_at')
                ->where('status', Lead::STATUS_SENT);

            if ($since = $this->parseSince((string) $this->option('since'))) {
                $query->where('created_at', '>=', $since);
            }
        }

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        $limit = max(1, (int) $this->option('limit'));
        $leads = $query->orderBy('created_at')->limit($limit)->get();

        if ($leads->isEmpty()) {
            $this->info('No leads awaiting Facebook sync.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf('Found %d lead(s)%s.', $leads->count(), $dryRun ? ' (dry-run)' : ''));

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($leads as $lead) {
            if ($dryRun) {
                $this->line(sprintf(
                    '  [%s] %s site=%s form=%s email=%s status=%s',
                    $lead->id,
                    $lead->created_at?->toDateTimeString() ?? '-',
                    $lead->site,
                    $lead->form_key,
                    $lead->email ?: '-',
                    $lead->status,
                ));
                continue;
            }

            try {
                $response = $facebook->sendLeadEvent($lead);

                if (! empty($response['skipped'])) {
                    $skipped++;
                    $this->warn(sprintf(
                        '  [%s] skipped — %s (site=%s)',
                        $lead->id,
                        $response['reason'] ?? 'skipped',
                        $lead->site,
                    ));
                    continue;
                }

                $lead->markFbSynced($response);
                $sent++;
                $this->line(sprintf(
                    '  [%s] sent — events_received=%s fbtrace=%s',
                    $lead->id,
                    $response['events_received'] ?? '-',
                    $response['fbtrace_id'] ?? '-',
                ));
            } catch (Throwable $e) {
                $failed++;
                $lead->markFbFailed($e);
                $this->warn(sprintf('  [%s] failed — %s', $lead->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Processed %d lead(s) — sent=%d skipped=%d failed=%d',
            $leads->count(),
            $sent,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function parseSince(string $value): ?Carbon
    {
        if ($value === '' || $value === '0') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([hdmw])$/i', $value, $m)) {
            $amount = (int) $m[1];
            return match (strtolower($m[2])) {
                'h' => now()->subHours($amount),
                'd' => now()->subDays($amount),
                'w' => now()->subWeeks($amount),
                'm' => now()->subMinutes($amount),
            };
        }

        return Carbon::parse($value);
    }
}
