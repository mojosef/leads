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
    | Frontend sites never deliver leads themselves — LeadPipeline writes rows
    | to the shared database and the leads-admin app's `leads:dispatch-pending`
    | cron ships them to Duo. These settings tune that command's retry policy.
    |
    | max_attempts: hard ceiling on dispatch retries before the lead is
    |   marked failed and removed from the auto-retry pool.
    |
    | backoff: seconds between attempts. The `leads:dispatch-pending` command
    |   skips leads whose backoff window (indexed by attempt count) has not
    |   yet elapsed.
    |
    */

    'dispatch' => [
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
    | with whatever data we have. Once a draft is older than this, the admin
    | app's `leads:finalize-drafts` cron promotes it to pending so the basic
    | contact info still reaches Duo.
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
    | office_id — override the default Duo office routing for this form.
    |
    | Facebook eligibility is NOT configured here. A lead fires the Facebook
    | 'Lead' event only when it carried real Facebook click attribution
    | (fbclid / _fbc) at creation — decided in LeadPipeline and frozen on the
    | row, so no per-form flag and no frontend/admin config to keep in sync.
    |
    | Example:
    |   'forms' => [
    |       'membership' => ['office_id' => 17],
    |   ],
    |
    */

    'forms' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact form composition
    |--------------------------------------------------------------------------
    |
    | Presentation and composition concerns only. Sites may enable/disable
    | questions, reorder them, and relax `required` on optional questions.
    | Canonical field names and answer values are fixed by the package's
    | enums and CANNOT be redefined here — override visible wording in
    | lang/vendor/contact-form/{locale}/form.php instead.
    |
    | questions: keyed by canonical field name (Question enum value).
    |   enabled  — whether the question is rendered and validated.
    |   required — whether an answer is mandatory. Optional questions left
    |              unanswered are omitted from the CRM payload (never coerced
    |              to 'unsure' or any other canonical value).
    |   order    — display order; defaults to enum declaration order.
    |
    | crm_properties: canonical field name => CRM property name, for fields
    |   where Duo's property differs from the package field name. Values are
    |   untouched — only the key is renamed.
    |
    | schema_version: stamped on every CRM payload as form_schema_version so
    |   downstream systems can detect future business-structure changes.
    |
    */

    'contact_form' => [

        'schema_version' => 1,

        'questions' => [
            'age_bracket' => ['enabled' => true, 'required' => true, 'order' => 10],
            'town' => ['enabled' => true, 'required' => true, 'order' => 20],
            'marital_status' => ['enabled' => true, 'required' => true, 'order' => 30],
            'search_goal' => ['enabled' => true, 'required' => true, 'order' => 40],
            'dating_challenges' => ['enabled' => true, 'required' => true, 'order' => 50],
            'meet_timeline' => ['enabled' => true, 'required' => true, 'order' => 60],
            'investment_range' => ['enabled' => true, 'required' => true, 'order' => 70],
            'support_level' => ['enabled' => true, 'required' => true, 'order' => 80],
            'first_name' => ['enabled' => true, 'required' => true, 'order' => 90],
            'email' => ['enabled' => true, 'required' => true, 'order' => 100],
            'phone_number' => ['enabled' => true, 'required' => true, 'order' => 110],
        ],

        'crm_properties' => [
            'first_name' => 'fname',
            'phone_number' => 'contact',
        ],

    ],

];
