<?php

namespace mojosef\Leads\Jobs;

use mojosef\Leads\LeadDispatcher;
use mojosef\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper around LeadDispatcher for sites that want immediate
 * queue-based dispatch. The scheduled `leads:dispatch-pending` command
 * does the same work via the same service for the cron-based admin-app
 * delivery model.
 */
class SendLeadToDuoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
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

    public function uniqueId(): string
    {
        return $this->leadId;
    }

    /**
     * @return array<int>
     */
    public function backoff(): array
    {
        return (array) config('leads.dispatch.backoff', [30, 120, 600, 3600, 21600]);
    }

    public function handle(LeadDispatcher $dispatcher): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            Log::warning('SendLeadToDuoJob: lead not found', ['lead_id' => $this->leadId]);
            return;
        }

        $dispatcher->dispatch($lead);
    }

    public function failed(Throwable $e): void
    {
        Log::alert('SendLeadToDuoJob exhausted retries', [
            'lead_id' => $this->leadId,
            'error' => $e->getMessage(),
        ]);
    }
}
