<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\Facebook\FacebookLeadService;
use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sends the Facebook CAPI 'Lead' event for eligible leads that have reached Duo
 * but not yet synced to Facebook. This is the sole delivery path for FB events:
 * leads are swept by query (fb_eligible + sent + fb_synced_at null) rather than
 * pushed onto a queue, so a missed send is simply retried on the next run — no
 * queue worker to keep alive, and no silent job loss.
 *
 * Designed for the leads-admin app's per-minute cron, mirroring
 * `leads:dispatch-pending`. Sends in-process so each attempt's result is
 * visible when run by hand. Scope it with --since: Meta rejects Lead events
 * older than 7 days, so leads that age out of the window stop being retried.
 */
class DispatchFacebookLeadsCommand extends Command
{
    protected $signature = 'leads:dispatch-facebook
        {--id=* : Send specific lead IDs (bypasses the eligibility/status filters)}
        {--site= : Restrict to a single site (defaults to all sites)}
        {--since= : Only consider leads created within this window (e.g. 24h, 7d). Meta rejects events older than 7 days.}
        {--limit=100 : Maximum leads to process in one run}
        {--dry-run : List candidate leads without sending}';

    protected $description = 'Send the Facebook CAPI Lead event for eligible leads not yet synced. Designed for the leads-admin app cron.';

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
