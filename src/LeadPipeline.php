<?php

namespace mojosef\Leads;

use mojosef\Leads\Exceptions\LeadStateException;
use mojosef\Leads\Models\Lead;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
            'event_id' => (string) Str::ulid(),
            'fb_eligible' => $this->hasFacebookClick($snapshot['cookies']),
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
     * Mark the lead complete: promote draft → pending and forget the session
     * draft key so a subsequent form view starts fresh. If the lead has
     * already moved past draft (a later step or the admin cron beat us to it),
     * this is a no-op.
     *
     * The package never dispatches the lead itself. Pending rows sit in the
     * shared database until the leads-admin app's `leads:dispatch-pending`
     * cron ships them to Duo.
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

        return $lead;
    }

    /**
     * For multi-step flows: keep the lead in draft and write any data we
     * already have. The lead is finalised later — either by a subsequent
     * explicit complete() call, or by the leads-admin app's
     * `leads:finalize-drafts` cron once the draft is older than
     * `draft_timeout_seconds`. If the user abandons, the cron completes the
     * lead with whatever data made it into the payload.
     *
     * The $delaySeconds argument is retained for call-site compatibility but
     * no longer has any effect: the timeout is governed entirely by
     * `leads.draft_timeout_seconds`, measured from the lead's created_at by
     * the admin cron.
     *
     * @param  array<string, mixed>  $data
     */
    public function scheduleCompletion(Lead $lead, ?int $delaySeconds = null, array $data = []): Lead
    {
        if (! empty($data)) {
            $lead = $this->update($lead, $data);
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
     * Re-queue a lead for delivery to Duo, used by the resend command. Resets
     * it to pending and clears the last error; the admin app's
     * `leads:dispatch-pending` cron picks it up on the next tick.
     */
    public function resend(Lead $lead): void
    {
        $lead->forceFill(['status' => Lead::STATUS_PENDING, 'last_error' => null])->save();
    }

    /**
     * Merge an arbitrary block of data into a draft lead's payload under a
     * named section. Designed for multi-step flows where any number of
     * follow-on components (questionnaire, lifestyle survey, preferences,
     * etc.) contribute data to a single lead.
     *
     * Does NOT auto-complete the lead. The lead stays as `draft` until either
     * the explicit `complete()` call or the admin app's `leads:finalize-drafts`
     * cron fires once the draft timeout elapses. Duo only ever receives the
     * lead as a single /lead/create with the full accumulated payload — there
     * is no /lead/append concept in this system.
     *
     * Idempotent per section: once `payload[$section.'_completed_at']` is
     * set, subsequent calls with the same section are no-ops.
     *
     * Non-draft leads are silently ignored (with a log warning). By the time
     * a lead has moved past draft, the timeout has fired and Duo has been
     * called — additional submissions can't be incorporated.
     *
     * @param  array<int|string, mixed>  $data
     */
    public function append(Lead $lead, string $section, array $data): Lead
    {
        if ($lead->hasCompletedSection($section)) {
            return $lead;
        }

        if ($lead->status !== Lead::STATUS_DRAFT) {
            Log::warning('LeadPipeline::append called on non-draft lead — ignoring', [
                'lead_id' => $lead->id,
                'status' => $lead->status,
                'section' => $section,
            ]);
            return $lead;
        }

        $lead->forceFill([
            'payload' => array_replace((array) $lead->payload, [
                $section => $data,
                $section.'_completed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $lead;
    }

    private function draftSessionKey(string $formKey): string
    {
        return "leads.draft.$formKey";
    }

    /**
     * A lead is Facebook-eligible when it carries Facebook click attribution —
     * an fbclid, or the _fbc cookie derived from one. Computed once here and
     * frozen onto the row, so the admin app that later sends the CAPI event
     * reads a fixed value rather than re-deriving it from config that may
     * differ between the frontend and admin apps.
     *
     * @param  array<string, mixed>  $cookies
     */
    private function hasFacebookClick(array $cookies): bool
    {
        return ! empty($cookies['fbclid']) || ! empty($cookies['_fbc']);
    }
}
