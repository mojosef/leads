<?php

namespace mojosef\Leads\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use mojosef\Leads\Models\Lead;

/**
 * Smoke detector for the lead pipeline. Emits backlog counts and — crucially —
 * exits non-zero when drafts are stuck beyond --warn-hours, the signal that
 * the `leads:finalize-drafts` cron has stopped or fallen behind. That cron is
 * the only delivery-adjacent process the fleet still owns; ingestion of
 * pending rows is Duo's job (it reads the shared database directly), so the
 * pending backlog is reported for visibility but does not drive the exit code.
 *
 * Schedule it every few minutes and surface failures via Laravel's
 * ->emailOutputOnFailure() or an external monitor that checks the exit code or
 * the `alert` field of --json output.
 */
class HealthCommand extends Command
{
    protected $signature = 'leads:health
        {--site= : Restrict to a single site (defaults to all sites)}
        {--warn-hours=48 : Age beyond which a stuck draft counts as stuck}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Report lead-pipeline backlog counts; exits non-zero when the finalize-drafts cron is behind.';

    public function handle(): int
    {
        $warnHours = max(1, (int) $this->option('warn-hours'));
        $staleBefore = now()->subHours($warnHours);

        // Exit-gating: the finalize-drafts cron drives this to zero.
        $stuck = [
            'drafts_stuck' => $this->base()
                ->where('status', Lead::STATUS_DRAFT)
                ->where('created_at', '<=', $staleBefore)
                ->count(),
        ];

        // Context: pending rows are Duo's ingestion queue — its backlog is
        // worth seeing here, but clearing it is Duo's responsibility.
        $totals = [
            'draft_total' => $this->base()->where('status', Lead::STATUS_DRAFT)->count(),
            'pending_total' => $this->base()->where('status', Lead::STATUS_PENDING)->count(),
            'pending_stale' => $this->base()
                ->where('status', Lead::STATUS_PENDING)
                ->where('created_at', '<=', $staleBefore)
                ->count(),
        ];

        $alerts = array_filter($stuck, static fn (int $n): bool => $n > 0);
        $alert = $alerts !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'site' => $this->option('site') ?: '*',
                'warn_hours' => $warnHours,
                'alert' => $alert,
                'stuck' => $stuck,
                'totals' => $totals,
            ], JSON_PRETTY_PRINT));

            return $alert ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf('Lead health — site=%s — warn age >%dh', $this->option('site') ?: '*', $warnHours));
        $this->newLine();

        $this->line('Stuck beyond threshold (a healthy pipeline clears these within minutes):');
        $this->line(sprintf('  drafts   (finalize-drafts behind?)    %d', $stuck['drafts_stuck']));
        $this->newLine();

        $this->line(sprintf(
            'Totals: draft=%d pending=%d (stale pending >%dh: %d — Duo ingestion backlog, not gated)',
            $totals['draft_total'],
            $totals['pending_total'],
            $warnHours,
            $totals['pending_stale'],
        ));
        $this->newLine();

        if ($alert) {
            $summary = implode(', ', array_map(
                static fn (string $k, int $v): string => "$k=$v",
                array_keys($alerts),
                array_values($alerts),
            ));
            $this->error("ALERT — backlog beyond {$warnHours}h: {$summary}");

            return self::FAILURE;
        }

        $this->info('OK — no backlog beyond threshold.');

        return self::SUCCESS;
    }

    private function base(): Builder
    {
        $query = Lead::query()->withoutGlobalScopes();

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        return $query;
    }
}
