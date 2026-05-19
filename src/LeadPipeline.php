<?php

namespace mojosef\Leads;

use mojosef\Leads\Exceptions\LeadStateException;
use mojosef\Leads\Jobs\AppendLeadToDuoJob;
use mojosef\Leads\Jobs\FinalizeLeadJob;
use mojosef\Leads\Jobs\SendLeadToDuoJob;
use mojosef\Leads\Models\Lead;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LeadPipeline
{
    public function __construct(private readonly AttributionCollector $attribution) {}

    /**
     * Start a new draft lead. Snapshots cookies/attribution and stores the
     * resulting ULID in the session so subsequent steps of a multi-step form
     * can resume it via currentDraft().
     *
     * @param  array<string, mixed>  $overrides
     */
    public function start(string $formKey, array $overrides = []): Lead
    {
        $snapshot = $this->attribution->snapshot();
        $formDefaults = (array) config("leads.forms.$formKey", []);
        $defaults = (array) config('leads.defaults', []);

        $lead = new Lead;
        $lead->id = (string) Str::ulid();
        $lead->forceFill([
            'site' => (string) config('leads.site'),
            'form_key' => $formKey,
            'status' => Lead::STATUS_DRAFT,
            'office_id' => $overrides['office_id']
                ?? $formDefaults['office_id']
                ?? $defaults['office_id']
                ?? null,
            'payload' => [],
            'attribution' => $snapshot['attribution'],
            'cookies' => $snapshot['cookies'],
            'fb_event_id' => (string) Str::ulid(),
            'fb_eligible' => $this->computeFacebookEligibility($formKey, $snapshot['cookies']),
            'ip_address' => $snapshot['ip_address'],
            'user_agent' => $snapshot['user_agent'],
            'previous_url' => $snapshot['previous_url'],
            'country_code' => $snapshot['country_code'],
            'session_id' => $snapshot['session_id'],
        ]);
        $lead->save();

        if (session()->isStarted()) {
            session()->put($this->draftSessionKey($formKey), $lead->id);
        }

        return $lead;
    }

    /**
     * Merge incoming data into the lead. Promotes a few well-known keys to
     * top-level columns for admin search; everything else lives in payload.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Lead $lead, array $data): Lead
    {
        if ($lead->status === Lead::STATUS_SENT) {
            throw new LeadStateException("Cannot update lead {$lead->id} — already sent.");
        }

        $promoted = [
            'fname' => Arr::get($data, 'fname') ?? Arr::get($data, 'name'),
            'lname' => Arr::get($data, 'lname'),
            'email' => Arr::get($data, 'email'),
            'contact' => Arr::get($data, 'contact') ?? Arr::get($data, 'phone'),
            'town' => Arr::get($data, 'town'),
        ];

        foreach ($promoted as $column => $value) {
            if ($value !== null && $value !== '') {
                $lead->{$column} = $value;
            }
        }

        $lead->payload = array_replace((array) $lead->payload, $data);
        $lead->save();

        return $lead;
    }

    /**
     * Mark the lead complete and dispatch it to Duo. The session draft key
     * is forgotten so a subsequent form view starts fresh. If the lead has
     * already moved past draft (a delayed FinalizeLeadJob already fired,
     * or another call beat us to it), this is a no-op.
     *
     * @param  array<string, mixed>  $finalData
     */
    public function complete(Lead $lead, array $finalData = []): Lead
    {
        if (! empty($finalData)) {
            $lead = $this->update($lead, $finalData);
        }

        if ($lead->status !== Lead::STATUS_DRAFT) {
            return $lead;
        }

        $lead->forceFill(['status' => Lead::STATUS_PENDING])->save();

        if (session()->isStarted()) {
            session()->forget($this->draftSessionKey($lead->form_key));
        }

        // Frontend sites with queue workers dispatch immediately for low-latency
        // delivery. Sites delegating to the leads-admin app's scheduled command
        // set leads.dispatch.auto_dispatch_job=false and the row simply sits
        // as pending until the admin app's cron picks it up.
        if (config('leads.dispatch.auto_dispatch_job', true)) {
            SendLeadToDuoJob::dispatch($lead->id);
        }

        return $lead;
    }

    /**
     * For multi-step flows: keep the lead in draft, write any data we already
     * have, and arrange for a delayed completion. If a later step calls
     * complete() first, this is no-op via complete()'s draft guard. If the
     * user abandons, the lead is completed with whatever data made it into
     * the payload.
     *
     * Two finalization paths, controlled by `leads.dispatch.auto_dispatch_job`:
     *
     * - true (sites with queue workers): dispatches `FinalizeLeadJob` with
     *   a delay. Fires exactly at T+delay.
     *
     * - false (sites delegating to the admin app): no job dispatched. The
     *   admin app's `leads:finalize-drafts` cron picks the draft up on the
     *   next minute tick after the timeout elapses.
     *
     * @param  array<string, mixed>  $data
     */
    public function scheduleCompletion(Lead $lead, ?int $delaySeconds = null, array $data = []): Lead
    {
        if (! empty($data)) {
            $lead = $this->update($lead, $data);
        }

        if ($lead->status !== Lead::STATUS_DRAFT) {
            return $lead;
        }

        if (config('leads.dispatch.auto_dispatch_job', true)) {
            $delay = $delaySeconds ?? (int) config('leads.draft_timeout_seconds', 600);
            FinalizeLeadJob::dispatch($lead->id)->delay(now()->addSeconds($delay));
        }

        return $lead;
    }

    /**
     * Return the current session's in-progress draft for a given form, if any.
     */
    public function currentDraft(string $formKey): ?Lead
    {
        if (! session()->isStarted()) {
            return null;
        }

        $id = session($this->draftSessionKey($formKey));

        if (! $id) {
            return null;
        }

        return Lead::query()
            ->where('id', $id)
            ->where('status', Lead::STATUS_DRAFT)
            ->first();
    }

    /**
     * Re-dispatch a lead for delivery to Duo, used by the resend command.
     */
    public function resend(Lead $lead): void
    {
        $lead->forceFill(['status' => Lead::STATUS_PENDING, 'last_error' => null])->save();

        if (config('leads.dispatch.auto_dispatch_job', true)) {
            SendLeadToDuoJob::dispatch($lead->id);
        }
    }

    /**
     * Add questionnaire answers to a lead. Behaviour depends on lead state:
     *
     * - DRAFT (the user finished the questionnaire before the finalize
     *   timeout fired): merge the answers into the payload and complete the
     *   lead. Duo receives a single /lead/create with the full data set.
     *
     * - SENT or PENDING (the user took longer than the timeout, so the lead
     *   has already been dispatched to Duo): write the answers locally for
     *   the audit trail and dispatch an AppendLeadToDuoJob so Duo's record
     *   gets enriched via /lead/append.
     *
     * Idempotent — re-submitting once questionnaire_completed_at is set is
     * a no-op.
     *
     * @param  array<int|string, mixed>  $answers
     */
    public function appendQuestionnaire(Lead $lead, array $answers): Lead
    {
        if ($lead->isQuestionnaireCompleted()) {
            return $lead;
        }

        $lead->forceFill([
            'payload' => array_replace((array) $lead->payload, [
                'questionnaire' => $answers,
                'questionnaire_completed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        if ($lead->status === Lead::STATUS_DRAFT) {
            return $this->complete($lead->fresh());
        }

        AppendLeadToDuoJob::dispatch($lead->id)->delay(now()->addMinutes(5));

        return $lead;
    }

    private function draftSessionKey(string $formKey): string
    {
        return "leads.draft.$formKey";
    }

    private function computeFacebookEligibility(string $formKey, array $cookies): bool
    {
        if (! empty($cookies['fbclid'])) {
            return true;
        }

        return (bool) config("leads.forms.$formKey.fb_eligible", false);
    }
}
