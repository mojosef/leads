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
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Name of the connection in config/database.php that points at the shared
    | leads database. The Lead model uses this connection. Duo owns the
    | database schema — the package ships no migrations.
    |
    */

    'connection' => env('LEADS_DB_CONNECTION', 'leads'),

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
    | Per-form-key configuration
    |--------------------------------------------------------------------------
    |
    | office_id — override the default Duo office routing for this form.
    |
    | Facebook eligibility is NOT configured here. A lead counts as
    | Facebook-eligible only when it carried real Facebook click attribution
    | (fbclid / _fbc) at creation — decided in LeadPipeline and frozen on the
    | row, so no per-form flag and no frontend/admin config to keep in sync.
    | Sites use it to gate the browser pixel; Duo owns the server-side CAPI.
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
    | Every key is an OPTIONAL override — with this section absent (e.g. a
    | site whose published config predates it) all questions are enabled and
    | required, ordered by enum declaration, and the default CRM property
    | mapping (first_name => fname, phone_number => contact) applies. Only
    | add entries for what you want to change.
    |
    | questions: keyed by canonical field name (Question enum value).
    |   enabled  — whether the question is rendered and validated.
    |   required — whether an answer is mandatory. Optional questions left
    |              unanswered are omitted from the CRM payload (never coerced
    |              to 'unsure' or any other canonical value).
    |   order    — display order for sites that iterate
    |              FormDefinition::questions(); irrelevant if your templates
    |              hardcode the field layout.
    |
    | crm_properties: canonical field name => CRM property name, for fields
    |   where Duo's property differs from the package field name. Values are
    |   untouched — only the key is renamed.
    |
    */

    'contact_form' => [

        'questions' => [
            // 'support_level' => ['required' => false],
            // 'town' => ['enabled' => false],
            // 'email' => ['order' => 1],
        ],

        'crm_properties' => [
            // 'age_bracket' => 'age_range',
        ],

    ],

];
