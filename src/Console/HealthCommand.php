<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Smoke detector for the delivery crons. Emits backlog counts and — crucially —
 * exits non-zero when leads are stuck in a transient state (draft / pending /
 * sending) or unsynced to Facebook for longer than --warn-hours. A healthy
 * fleet clears every transient state within a minute or two (pending can take
 * longer while backing off, hence the generous default window), so a non-zero
 * backlog past the threshold means a cron has stopped or is falling behind.
 *
 * Schedule it every few minutes and surface failures via Laravel's
 * ->emailOutputOnFailure() or an external monitor that checks the exit code or
 * the `alert` field of --json output.
 *
 * Terminal states (FB events aged past Meta's 7-day window, Duo leads that
 * exhausted their retries) are reported but do NOT drive the exit code — they
 * won't self-heal, so alerting on them would ring forever. They need a human,
 * not a retry.
 */
class HealthCommand extends Command
{
    protected $signature = 'leads:health
        {--site= : Restrict to a single site (defaults to all sites)}
        {--warn-hours=48 : Age beyond which a transient-state lead counts as stuck}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Report lead-pipeline backlog counts; exits non-zero when a delivery cron is behind.';

    private const FB_WINDOW_DAYS = 7;

    public function handle(): int
    {
        $warnHours = max(1, (int) $this->option('warn-hours'));
        $staleBefore = now()->subHours($warnHours);
        $fbExpiredBefore = now()->subDays(self::FB_WINDOW_DAYS);

        // Exit-gating: transient states that a running cron drives to zero.
        $stuck = [
            'drafts_stuck' => $this->base()
                ->where('status', Lead::STATUS_DRAFT)
                ->where('created_at', '<=', $staleBefore)
                ->count(),
            'pending_stuck' => $this->base()
                ->where('status', Lead::STATUS_PENDING)
                ->where('created_at', '<=', $staleBefore)
                ->count(),
            'sending_stuck' => $this->base()
                ->where('status', Lead::STATUS_SENDING)
                ->where('updated_at', '<=', $staleBefore)
                ->count(),
            'fb_at_risk' => $this->fbUnsynced()
                ->where('created_at', '<=', $staleBefore)
                ->where('created_at', '>', $fbExpiredBefore)
                ->count(),
        ];

        // Informational: damage already done, needs a human not a retry.
        $terminal = [
            'fb_expired' => $this->fbUnsynced()
                ->where('created_at', '<=', $fbExpiredBefore)
                ->count(),
            'failed_total' => $this->base()
                ->where('status', Lead::STATUS_FAILED)
                ->count(),
        ];

        // Context: baseline totals (transient totals should hover near zero).
        $totals = [
            'draft_total' => $this->base()->where('status', Lead::STATUS_DRAFT)->count(),
            'pending_total' => $this->base()->where('status', Lead::STATUS_PENDING)->count(),
            'sending_total' => $this->base()->where('status', Lead::STATUS_SENDING)->count(),
            'fb_unsynced_total' => $this->fbUnsynced()->count(),
        ];

        $alerts = array_filter($stuck, static fn (int $n): bool => $n > 0);
        $alert = $alerts !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'site' => $this->option('site') ?: '*',
                'warn_hours' => $warnHours,
                'alert' => $alert,
                'stuck' => $stuck,
                'terminal' => $terminal,
                'totals' => $totals,
            ], JSON_PRETTY_PRINT));

            return $alert ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf('Lead health — site=%s — warn age >%dh', $this->option('site') ?: '*', $warnHours));
        $this->newLine();

        $this->line('Stuck beyond threshold (a healthy pipeline clears these within minutes):');
        $this->line(sprintf('  drafts   (finalize-drafts behind?)    %d', $stuck['drafts_stuck']));
        $this->line(sprintf('  pending  (dispatch-pending behind?)   %d', $stuck['pending_stuck']));
        $this->line(sprintf('  sending  (orphaned mid-dispatch)      %d', $stuck['sending_stuck']));
        $this->line(sprintf('  FB unsynced (dispatch-facebook behind?) %d', $stuck['fb_at_risk']));
        $this->newLine();

        $this->line('Terminal — need a human, not a retry (not counted toward exit code):');
        $this->line(sprintf('  FB expired >%dd, unrecoverable        %d', self::FB_WINDOW_DAYS, $terminal['fb_expired']));
        $this->line(sprintf('  failed Duo (exhausted retries)        %d', $terminal['failed_total']));
        $this->newLine();

        $this->line(sprintf(
            'Totals: draft=%d pending=%d sending=%d | FB unsynced (all ages)=%d',
            $totals['draft_total'],
            $totals['pending_total'],
            $totals['sending_total'],
            $totals['fb_unsynced_total'],
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

    private function fbUnsynced(): Builder
    {
        return $this->base()
            ->where('status', Lead::STATUS_SENT)
            ->where('fb_eligible', true)
            ->whereNull('fb_synced_at');
    }
}
