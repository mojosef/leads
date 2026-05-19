<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site identifier
    |--------------------------------------------------------------------------
    |
    | Stamped on every Lead row. The global SiteScope filters reads by this
    | value so consuming sites only ever see their own leads. The unified
    | admin UI disables the scope to read across the fleet.
    |
    */

    'site' => env('LEADS_SITE'),

    /*
    |--------------------------------------------------------------------------
    | Schema ownership
    |--------------------------------------------------------------------------
    |
    | Only one site in the fleet should run the package migrations against
    | the shared database. Set LEADS_SCHEMA_OWNER=true on that site; all
    | other sites leave it false so `php artisan migrate` doesn't race.
    |
    */

    'schema_owner' => env('LEADS_SCHEMA_OWNER', false),

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Name of the connection in config/database.php that points at the shared
    | leads database. The Lead model and migrations use this connection.
    |
    */

    'connection' => env('LEADS_DB_CONNECTION', 'leads'),

    /*
    |--------------------------------------------------------------------------
    | Duo CRM
    |--------------------------------------------------------------------------
    */

    'duo' => [
        'lead_create_url' => env('LEADS_DUO_URL', 'https://myduo.app/v1/lead/create'),
        'timeout' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatch behaviour
    |--------------------------------------------------------------------------
    |
    | auto_dispatch_job: when true (the default), LeadPipeline::complete()
    |   dispatches SendLeadToDuoJob immediately. Sites with queue workers
    |   keep this on for low-latency delivery.
    |
    |   When false, the lead is written to the shared database as `pending`
    |   and nothing else happens — a separate leads-admin app picks it up
    |   via the `leads:dispatch-pending` artisan command on a cron schedule.
    |   Frontend sites can use this mode to avoid running queue workers at all.
    |
    | max_attempts: hard ceiling on dispatch retries before the lead is
    |   marked failed and removed from the auto-retry pool.
    |
    | backoff: seconds between attempts. Used by both the queue job (Laravel
    |   reads it for delay-based retries) and the scheduled command (which
    |   skips leads whose backoff window has not yet elapsed).
    |
    */

    'dispatch' => [
        'auto_dispatch_job' => (bool) env('LEADS_AUTO_DISPATCH_JOB', true),
        'max_attempts' => (int) env('LEADS_MAX_ATTEMPTS', 5),
        'backoff' => [30, 120, 600, 3600, 21600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Per-site defaults merged into every Lead at start(). Forms can still
    | override these by passing overrides into LeadPipeline::start().
    |
    */

    'defaults' => [
        'prospect_queue_id' => env('LEADS_PROSPECT_QUEUE_ID', 5),
        'office_id' => env('LEADS_OFFICE_ID', 21),
    ],

    /*
    |--------------------------------------------------------------------------
    | Draft completion timeout
    |--------------------------------------------------------------------------
    |
    | For multi-step flows where a form schedules its completion (e.g. the
    | PaidSearchForm waits for the user to finish a thank-you-page
    | questionnaire), this is how long we wait before finalising the lead
    | with whatever data we have. Once this elapses, FinalizeLeadJob fires
    | and completes the lead so the basic contact info still reaches Duo.
    |
    */

    'draft_timeout_seconds' => (int) env('LEADS_DRAFT_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Facebook
    |--------------------------------------------------------------------------
    |
    | A lead is "Facebook eligible" (i.e. should also fire a CAPI Lead event)
    | when its lead_source_id is in this list OR when an fbclid was captured.
    |
    */

    'facebook' => [
        'lead_source_ids' => array_filter(array_map('intval', explode(',', (string) env('LEADS_FB_SOURCE_IDS', '59')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-form-key overrides
    |--------------------------------------------------------------------------
    |
    | Example:
    |   'forms' => [
    |       'ppc_contact' => ['lead_source_id' => 57],
    |   ],
    |
    */

    'forms' => [],

];
