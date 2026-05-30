<?php

namespace mojosef\Leads\Console;

use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;
use Illuminate\Console\Command;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ResendFailedLeadsCommand extends Command
{
    protected $signature = 'leads:resend
        {--id=* : Resend specific lead IDs}
        {--site= : Restrict to a single site (defaults to this site; pass "*" for all)}
        {--status=failed : Status filter (failed|pending|sending)}
        {--since=24h : Only consider leads updated within this window (e.g. 24h, 7d)}
        {--dry-run : Show what would be resent without dispatching}';

    protected $description = 'Re-dispatch failed leads to Duo.';

    public function handle(LeadPipeline $pipeline): int
    {
        $query = Lead::query()->withoutGlobalScopes();

        $site = $this->option('site') ?: config('leads.site');
        if ($site && $site !== '*') {
            $query->where('site', $site);
        }

        if ($ids = (array) $this->option('id')) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('status', $this->option('status'));

            if ($since = $this->parseSince((string) $this->option('since'))) {
                $query->where('updated_at', '>=', $since);
            }
        }

        $leads = $query->orderBy('updated_at')->get();

        if ($leads->isEmpty()) {
            $this->info('No leads matched the filter.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d lead(s)%s.', $leads->count(), $this->option('dry-run') ? ' (dry-run)' : ''));

        foreach ($leads as $lead) {
            $this->line(sprintf(
                '  [%s] %s site=%s form=%s email=%s status=%s attempts=%d',
                $lead->id,
                $lead->created_at?->toDateTimeString() ?? '-',
                $lead->site,
                $lead->form_key,
                $lead->email ?: '-',
                $lead->status,
                $lead->attempts,
            ));

            if (! $this->option('dry-run')) {
                $pipeline->resend($lead);
            }
        }

        return self::SUCCESS;
    }

    private function parseSince(string $value): ?CarbonInterface
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
