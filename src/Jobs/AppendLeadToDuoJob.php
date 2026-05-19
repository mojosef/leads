<?php

namespace mojosef\Leads\Jobs;

use mojosef\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AppendLeadToDuoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 10;
    public int $timeout = 30;

    public function __construct(public readonly string $leadId) {}

    /**
     * @return array<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 1800];
    }

    public function handle(): void
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            Log::warning('AppendLeadToDuoJob: lead not found', ['lead_id' => $this->leadId]);
            return;
        }

        // Wait for the original Duo create to succeed before appending. If the
        // lead is still pending/sending, release back to the queue so Duo has
        // time to acknowledge the create.
        if ($lead->status !== Lead::STATUS_SENT) {
            $this->release(60);
            return;
        }

        $appendData = $this->buildAppendData($lead);

        if (empty($appendData)) {
            Log::info('AppendLeadToDuoJob: nothing to append', ['lead_id' => $lead->id]);
            return;
        }

        $token = $this->lookupToken($lead);
        $url = $this->appendUrl();

        try {
            $response = Http::timeout((int) config('leads.duo.timeout', 20))
                ->acceptJson()
                ->post($url, [
                    'token' => $token,
                    'data' => $appendData,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Duo /lead/append returned HTTP {$response->status()}: ".mb_substr((string) $response->body(), 0, 1000)
                );
            }
        } catch (Throwable $e) {
            Log::warning('AppendLeadToDuoJob failed, will retry', [
                'lead_id' => $lead->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build the append payload from the Lead row. The lead's payload is the
     * canonical source — never the job constructor — so retries and admin
     * resends always see the latest data.
     *
     * @return array<string, mixed>
     */
    private function buildAppendData(Lead $lead): array
    {
        $payload = $lead->payload ?? [];

        if (empty($payload['questionnaire'])) {
            return [];
        }

        return ['questionnaire' => $payload['questionnaire']];
    }

    public function failed(Throwable $e): void
    {
        Log::alert('AppendLeadToDuoJob exhausted retries', [
            'lead_id' => $this->leadId,
            'error' => $e->getMessage(),
        ]);
    }

    private function lookupToken(Lead $lead): string
    {
        // Honour a payload-provided external_token (e.g. legacy HMAC), otherwise
        // use the Lead ULID — same fallback as Lead::buildDuoPayload().
        $payload = $lead->payload ?? [];

        return (string) ($payload['external_token'] ?? $lead->id);
    }

    private function appendUrl(): string
    {
        $createUrl = (string) config('leads.duo.lead_create_url');

        return preg_replace('#/lead/create$#', '/lead/append', $createUrl) ?? $createUrl;
    }
}
