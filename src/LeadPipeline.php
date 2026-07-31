<?php

namespace mojosef\Leads;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use mojosef\Leads\Models\Lead;

class LeadPipeline
{
    public function __construct(private readonly AttributionCollector $attribution) {}

    /**
     * Build a new Lead in memory. Snapshots cookies/attribution and assigns
     * the ULID id and event_id up front so redirects and tracking can use
     * them immediately — but nothing is persisted. The single database
     * insert happens in complete(); an abandoned form leaves no row.
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
            'status' => Lead::STATUS_PENDING,
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

        return $lead;
    }

    /**
     * Merge the submitted data into the lead and persist it as `pending` —
     * the one and only insert. Promotes a few well-known keys to top-level
     * columns for admin search; everything else lives in payload.
     *
     * Calling complete() on a lead that has already been persisted is a
     * logged no-op, so a double-clicked submit cannot create a second row.
     *
     * The package never delivers the lead anywhere. Pending rows sit in the
     * shared database, which Duo reads directly to ingest new leads.
     *
     * @param  array<string, mixed>  $finalData
     */
    public function complete(Lead $lead, array $finalData = []): Lead
    {
        if ($lead->exists) {
            Log::warning('LeadPipeline::complete called on an already-persisted lead — ignoring', [
                'lead_id' => $lead->id,
                'status' => $lead->status,
            ]);

            return $lead;
        }

        $promoted = [
            'fname' => Arr::get($finalData, 'fname') ?? Arr::get($finalData, 'name'),
            'lname' => Arr::get($finalData, 'lname'),
            'email' => Arr::get($finalData, 'email'),
            'contact' => Arr::get($finalData, 'contact') ?? Arr::get($finalData, 'phone'),
            'town' => Arr::get($finalData, 'town'),
        ];

        foreach ($promoted as $column => $value) {
            if ($value !== null && $value !== '') {
                $lead->{$column} = $value;
            }
        }

        $lead->payload = array_replace((array) $lead->payload, $finalData);
        $lead->status = Lead::STATUS_PENDING;
        $lead->save();

        return $lead;
    }

    /**
     * A lead is Facebook-eligible when it carries Facebook click attribution —
     * an fbclid, or the _fbc cookie derived from one. Computed once here and
     * frozen onto the row, so the frontend firing the browser pixel (and Duo,
     * which sends the server-side CAPI event) reads a fixed value rather than
     * re-deriving it from config.
     *
     * @param  array<string, mixed>  $cookies
     */
    private function hasFacebookClick(array $cookies): bool
    {
        return ! empty($cookies['fbclid']) || ! empty($cookies['_fbc']);
    }
}
