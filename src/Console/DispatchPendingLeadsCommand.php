<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\LeadDispatcher;
use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Throwable;

/**
 * Picks up pending leads from the shared database and ships them to Duo.
 * Designed to run from the leads-admin app on a per-minute cron, decoupling
 * Duo delivery from the frontend sites' request cycle.
 *
 * Honours backoff: leads with a recent last_error_at are skipped until the
 * delay for their attempt count has elapsed.
 */
class DispatchPendingLeadsCommand extends Command
{
    protected $signature = 'leads:dispatch-pending
        {--limit=50 : Maximum leads to process in one run}
        {--site= : Restrict to a single site (defaults to all sites — typical for the admin app)}
        {--dry-run : List candidate leads without sending}';

    protected $description = 'Send pending leads to Duo. Designed for the leads-admin app cron.';

    public function handle(LeadDispatcher $dispatcher): int
    {
        $query = Lead::query()
            ->withoutGlobalScopes()
            ->where('status', Lead::STATUS_PENDING)
            ->orderBy('created_at');

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        $limit = max(1, (int) $this->option('limit'));
        $candidates = $query->limit($limit)->get();

        if ($candidates->isEmpty()) {
            $this->info('No pending leads.');
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $lead) {
            if (! $dispatcher->isReadyToDispatch($lead)) {
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '  [%s] site=%s form=%s email=%s attempts=%d',
                    $lead->id,
                    $lead->site,
                    $lead->form_key,
                    $lead->email ?: '-',
                    $lead->attempts,
                ));
                continue;
            }

            try {
                if ($dispatcher->dispatch($lead)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('  [%s] failed: %s', $lead->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Processed %d candidates — sent=%d failed=%d skipped(backoff)=%d',
            $candidates->count(),
            $sent,
            $failed,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
