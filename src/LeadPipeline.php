<?php

namespace mojosef\Leads;

use mojosef\Leads\Exceptions\LeadStateException;
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

        $leadSourceId = $overrides['lead_source_id']
            ?? $formDefaults['lead_source_id']
            ?? $defaults['lead_source_id']
            ?? null;

        $lead = new Lead;
        $lead->id = (string) Str::ulid();
        $lead->forceFill([
            'site' => (string) config('leads.site'),
            'form_key' => $formKey,
            'status' => Lead::STATUS_DRAFT,
            'lead_source_id' => $leadSourceId,
            'prospect_queue_id' => $overrides['prospect_queue_id']
                ?? $formDefaults['prospect_queue_id']
                ?? $defaults['prospect_queue_id']
                ?? null,
            'office_id' => $overrides['office_id']
                ?? $formDefaults['office_id']
                ?? $defaults['office_id']
                ?? null,
            'payload' => [],
            'attribution' => $snapshot['attribution'],
            'cookies' => $snapshot['cookies'],
            'fb_event_id' => (string) Str::ulid(),
            'fb_eligible' => $this->computeFacebookEligibility($leadSourceId, $snapshot['cookies']),
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

        if (isset($data['lead_source_id'])) {
            $lead->lead_source_id = (int) $data['lead_source_id'];
            $lead->fb_eligible = $this->computeFacebookEligibility($lead->lead_source_id, (array) $lead->cookies);
        }

        $lead->payload = array_replace((array) $lead->payload, $data);
        $lead->save();

        return $lead;
    }

    /**
     * Mark the lead complete and dispatch it to Duo. The session draft key
     * is forgotten so a subsequent form view starts fresh.
     *
     * @param  array<string, mixed>  $finalData
     */
    public function complete(Lead $lead, array $finalData = []): Lead
    {
        if ($lead->status === Lead::STATUS_SENT) {
            return $lead;
        }

        if (! empty($finalData)) {
            $lead = $this->update($lead, $finalData);
        }

        $lead->forceFill(['status' => Lead::STATUS_PENDING])->save();

        if (session()->isStarted()) {
            session()->forget($this->draftSessionKey($lead->form_key));
        }

        SendLeadToDuoJob::dispatch($lead->id);

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
        SendLeadToDuoJob::dispatch($lead->id);
    }

    private function draftSessionKey(string $formKey): string
    {
        return "leads.draft.$formKey";
    }

    private function computeFacebookEligibility(?int $leadSourceId, array $cookies): bool
    {
        if (! empty($cookies['fbclid'])) {
            return true;
        }

        if ($leadSourceId === null) {
            return false;
        }

        $fbSourceIds = (array) config('leads.facebook.lead_source_ids', []);

        return in_array((int) $leadSourceId, $fbSourceIds, true);
    }
}
