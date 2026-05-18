<?php

namespace mojosef\Leads\Jobs;

use mojosef\Leads\Facebook\FacebookLeadService;
use mojosef\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendLeadToFacebookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly string $leadId) {}

    /**
     * @return array<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(FacebookLeadService $facebook): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            return;
        }

        if ($lead->fb_synced_at !== null) {
            return;
        }

        $response = $facebook->sendLeadEvent($lead);
        $lead->markFbSynced($response);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('SendLeadToFacebookJob exhausted retries', [
            'lead_id' => $this->leadId,
            'error' => $e->getMessage(),
        ]);
    }
}
