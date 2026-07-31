<?php

namespace mojosef\Leads\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use mojosef\Leads\Models\Lead;

/**
 * Smoke detector for the lead pipeline. Emits backlog counts and — crucially —
 * exits non-zero when pending rows sit beyond --warn-hours, the signal that
 * Duo has stopped ingesting from the shared database. The package writes each
 * lead as a single `pending` row at submission; ingestion is Duo's job, so a
 * stale pending backlog is the only fleet-visible failure mode left.
 *
 * Schedule it every few minutes and surface failures via Laravel's
 * ->emailOutputOnFailure() or an external monitor that checks the exit code or
 * the `alert` field of --json output.
 */
class HealthCommand extends Command
{
    protected $signature = 'leads:health
        {--site= : Restrict to a single site (defaults to all sites)}
        {--warn-hours=48 : Age beyond which a pending lead counts as stuck}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Report lead-pipeline backlog counts; exits non-zero when Duo ingestion is behind.';

    public function handle(): int
    {
        $warnHours = max(1, (int) $this->option('warn-hours'));
        $staleBefore = now()->subHours($warnHours);

        // Exit-gating: Duo's ingestion drives this to zero.
        $stuck = [
            'pending_stale' => $this->base()
                ->where('status', Lead::STATUS_PENDING)
                ->where('created_at', '<=', $staleBefore)
                ->count(),
        ];

        $totals = [
            'pending_total' => $this->base()->where('status', Lead::STATUS_PENDING)->count(),
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

        $this->line(sprintf(
            'Totals: pending=%d (stale pending >%dh: %d — Duo ingestion backlog)',
            $totals['pending_total'],
            $warnHours,
            $stuck['pending_stale'],
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
