<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finalizes draft leads whose multi-step timeout has elapsed. Designed for
 * the leads-admin app cron so frontend sites don't need a queue worker
 * just to fire FinalizeLeadJob.
 *
 * A draft is "expired" once `now() >= created_at + draft_timeout_seconds`.
 * The command transitions it to pending so the next `leads:dispatch-pending`
 * tick (or the immediate SendLeadToDuoJob, if auto_dispatch_job=true on the
 * admin app) ships it to Duo.
 */
class FinalizeDraftsCommand extends Command
{
    protected $signature = 'leads:finalize-drafts
        {--limit=200 : Maximum drafts to finalize in one run}
        {--site= : Restrict to a single site (defaults to all sites)}
        {--dry-run : List expired drafts without finalizing}';

    protected $description = 'Finalize expired draft leads. Designed for the leads-admin app cron.';

    public function handle(LeadPipeline $pipeline): int
    {
        $timeoutSeconds = (int) config('leads.draft_timeout_seconds', 600);
        $cutoff = now()->subSeconds($timeoutSeconds);

        $query = Lead::query()
            ->withoutGlobalScopes()
            ->where('status', Lead::STATUS_DRAFT)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at');

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        $limit = max(1, (int) $this->option('limit'));
        $candidates = $query->limit($limit)->get();

        if ($candidates->isEmpty()) {
            $this->info('No expired drafts.');
            return self::SUCCESS;
        }

        $finalized = 0;
        $failed = 0;

        foreach ($candidates as $lead) {
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '  [%s] site=%s form=%s created=%s email=%s',
                    $lead->id,
                    $lead->site,
                    $lead->form_key,
                    $lead->created_at?->toDateTimeString() ?? '-',
                    $lead->email ?: '-',
                ));
                continue;
            }

            try {
                $pipeline->complete($lead);
                $finalized++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('  [%s] failed: %s', $lead->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Processed %d expired draft(s) — finalized=%d failed=%d',
            $candidates->count(),
            $finalized,
            $failed,
        ));

        return self::SUCCESS;
    }
}
