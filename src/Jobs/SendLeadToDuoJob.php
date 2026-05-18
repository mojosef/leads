<?php

namespace mojosef\Leads\Jobs;

use mojosef\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendLeadToDuoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
    public int $timeout = 30;

    public function __construct(public readonly string $leadId) {}

    public function uniqueId(): string
    {
        return $this->leadId;
    }

    /**
     * @return array<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 3600, 21600];
    }

    public function handle(): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            Log::warning('SendLeadToDuoJob: lead not found', ['lead_id' => $this->leadId]);
            return;
        }

        if ($lead->status === Lead::STATUS_SENT) {
            return;
        }

        $lead->markSending();

        try {
            $response = Http::timeout((int) config('leads.duo.timeout', 20))
                ->acceptJson()
                ->asForm()
                ->post(config('leads.duo.lead_create_url'), $lead->buildDuoPayload());

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Duo returned HTTP {$response->status()}: ".mb_substr((string) $response->body(), 0, 1000)
                );
            }

            $body = $this->decodeBody($response->body());
            $lead->markSent($response->status(), $body);

            if ($lead->isFacebookEligible()) {
                SendLeadToFacebookJob::dispatch($lead->id);
            }
        } catch (Throwable $e) {
            $lead->incrementAttempts();
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if ($lead) {
            $lead->markFailed($e);
        }

        Log::alert('SendLeadToDuoJob exhausted retries', [
            'lead_id' => $this->leadId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
