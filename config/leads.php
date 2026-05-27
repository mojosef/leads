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
    | Queue routing
    |--------------------------------------------------------------------------
    |
    | Package jobs (SendLeadToDuoJob, SendLeadToFacebookJob, FinalizeLeadJob)
    | are routed onto this connection/queue at construction time. The shared
    | fleet setup points every site at a single Redis connection drained by
    | a single queue worker on the admin app, so leads from all 6 sites go
    | through one pipeline.
    |
    | connection: name of a queue connection defined in the host app's
    |   config/queue.php. When null, the host's default queue connection is
    |   used — useful for tests or sites that haven't been migrated yet.
    |
    | name: the queue name within that connection. Workers must include this
    |   in --queue= to drain it.
    |
    */

    'queue' => [
        'connection' => env('LEADS_QUEUE_CONNECTION'),
        'name' => env('LEADS_QUEUE_NAME', 'leads'),
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
    | sites: per-site Facebook credentials. The FacebookLeadService looks up
    |   the entry matching the Lead's `site` column and uses those credentials
    |   for the CAPI call. Required when the admin app processes leads from
    |   multiple sites — each brand has its own Pixel ID and access token.
    |
    |   Pixel IDs are not secret (they appear in page HTML) so hardcode them
    |   here. Access tokens ARE secret — pull them from env.
    |
    |   If no per-site entry exists, FacebookLeadService falls back to the
    |   global esign/laravel-conversions-api config (single-tenant setup).
    |
    */

    'facebook' => [
        'sites' => [
            // 'elect-club' => [
            //     'pixel_id' => '1124100455670536',
            //     'access_token' => env('FB_ELECT_CLUB_TOKEN'),
            //     'test_code' => env('FB_ELECT_CLUB_TEST_CODE'),
            // ],
            // 'attractive-partners' => [
            //     'pixel_id' => '...',
            //     'access_token' => env('FB_ATTRACTIVE_PARTNERS_TOKEN'),
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-form-key configuration
    |--------------------------------------------------------------------------
    |
    | fb_eligible — when true, every lead from this form fires a Facebook
    |   CAPI 'Lead' event regardless of whether an fbclid was captured.
    |   Use it for forms that live exclusively on Facebook ad landing pages
    |   where the click ID is reliable enough you want CAPI even when the
    |   cookie is lost in transit (mobile redirects, consent gates).
    |
    | office_id — override the default Duo office routing for this form.
    |
    | Example:
    |   'forms' => [
    |       'ppc_contact'  => ['fb_eligible' => true],
    |       'paid_search'  => ['fb_eligible' => true],
    |       'membership'   => ['office_id' => 17],
    |   ],
    |
    */

    'forms' => [
        'ppc_contact' => ['fb_eligible' => true],
        'paid_search' => ['fb_eligible' => true],
    ],

];
