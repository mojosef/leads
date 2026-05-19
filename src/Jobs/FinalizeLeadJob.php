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

    public function __construct(public readonly string $leadId) {}

    /**
     * Finalize a draft lead — if the user completed the follow-on form
     * (questionnaire etc.) in time, the lead will already have transitioned
     * out of draft and this job no-ops. Otherwise we complete the lead with
     * whatever data we have, so the user's contact info still reaches Duo.
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

        $pipeline->complete($lead);
    }
}
