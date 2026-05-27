<?php

namespace mojosef\Leads\Jobs;

use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeLeadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly string $leadId)
    {
        if ($connection = config('leads.queue.connection')) {
            $this->onConnection($connection);
        }

        if ($queue = config('leads.queue.name')) {
            $this->onQueue($queue);
        }
    }

    /**
     * Finalize a draft lead — if the user completed the follow-on form
     * (questionnaire etc.) in time, the lead will already have transitioned
     * out of draft and this job no-ops. Otherwise we complete the lead with
     * whatever data we have, so the user's contact info still reaches Duo.
     *
     * Belt-and-braces: on a `sync` queue driver, `dispatch()->delay()` is
     * ignored and this job runs immediately. We re-check the elapsed time
     * before completing so the timeout is respected regardless of driver.
     * On sync queue the job no-ops; the lead is finalised later by
     * `leads:finalize-drafts` (or by the follow-on form completing it).
     */
    public function handle(LeadPipeline $pipeline): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            Log::warning('FinalizeLeadJob: lead not found', ['lead_id' => $this->leadId]);
            return;
        }

        if ($lead->status !== Lead::STATUS_DRAFT) {
            return;
        }

        $timeoutSeconds = (int) config('leads.draft_timeout_seconds', 600);
        $earliestFinalize = $lead->created_at?->copy()->addSeconds($timeoutSeconds);

        if ($earliestFinalize && $earliestFinalize->isFuture()) {
            return;
        }

        $pipeline->complete($lead);
    }
}
