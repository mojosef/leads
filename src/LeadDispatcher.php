<?php

namespace mojosef\Leads;

use mojosef\Leads\Jobs\SendLeadToFacebookJob;
use mojosef\Leads\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Single owner of the "send this lead to Duo" responsibility. Both the queue
 * job (immediate dispatch from frontend sites) and the scheduled command
 * (batch processing from the admin app) call this service so the behaviour
 * is identical regardless of context.
 *
 * The dispatcher is also responsible for the soft-retry semantics needed
 * by the command path: on failure, it transitions the lead back to pending
 * (or to failed once max_attempts is exhausted) and records the error.
 * Backoff between attempts is enforced by the caller via isReadyToDispatch().
 */
class LeadDispatcher
{
    /**
     * Atomically claim a pending lead and send it to Duo. Returns true if
     * the send succeeded, false if another worker already claimed it.
     *
     * @throws \RuntimeException on HTTP failure (caller decides whether to retry)
     */
    public function dispatch(Lead $lead): bool
    {
        if (! $this->claim($lead)) {
            return false;
        }

        $lead->refresh();

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

            return true;
        } catch (Throwable $e) {
            $this->softFail($lead, $e);
            throw $e;
        }
    }

    /**
     * Has the backoff window elapsed since the last failure? Used by the
     * scheduled command to skip leads that are not yet ready for retry.
     */
    public function isReadyToDispatch(Lead $lead): bool
    {
        if ($lead->status !== Lead::STATUS_PENDING) {
            return false;
        }

        if (! $lead->last_error_at) {
            return true;
        }

        $backoff = $this->backoffFor((int) $lead->attempts);

        return $lead->last_error_at->copy()->addSeconds($backoff)->lessThanOrEqualTo(now());
    }

    /**
     * Atomic pending → sending transition. Returns true if this caller
     * claimed the lead; false if another worker beat us to it.
     */
    private function claim(Lead $lead): bool
    {
        $affected = Lead::query()
            ->withoutGlobalScopes()
            ->whereKey($lead->id)
            ->where('status', Lead::STATUS_PENDING)
            ->update([
                'status' => Lead::STATUS_SENDING,
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    /**
     * Record the failure and decide whether the lead should remain available
     * for another retry (pending) or move to terminal failed state.
     */
    private function softFail(Lead $lead, Throwable $e): void
    {
        $attempts = (int) $lead->attempts + 1;
        $maxAttempts = (int) config('leads.dispatch.max_attempts', 5);

        $lead->forceFill([
            'status' => $attempts >= $maxAttempts ? Lead::STATUS_FAILED : Lead::STATUS_PENDING,
            'attempts' => $attempts,
            'last_error_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 5000),
        ])->save();

        if ($attempts >= $maxAttempts) {
            Log::alert('LeadDispatcher: lead failed after max attempts', [
                'lead_id' => $lead->id,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function backoffFor(int $attempts): int
    {
        $schedule = (array) config('leads.dispatch.backoff', [30, 120, 600, 3600, 21600]);
        $index = max(0, $attempts - 1);

        return (int) ($schedule[$index] ?? end($schedule));
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
