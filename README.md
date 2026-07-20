# mojosef/leads

Durable lead pipeline shared across the Duo CRM site fleet. Every lead is persisted to a row in the shared `leads` database before any outbound HTTP, so a Duo or Facebook outage no longer loses revenue. Delivery to both Duo and Facebook is driven by the admin app's crons sweeping the table — no queues, no workers. Multi-step forms become a side-effect of having a draft row to update.

## Installation

```bash
composer require mojosef/leads
```

The service provider auto-registers the shared `leads` database connection from the env keys below — no `config/database.php` edits are needed. The registration is guarded: if the host app already defines a connection of the same name, that one wins. The package queues nothing, so no Redis or queue connection is registered.

Add the following env keys to each consuming site. Only `LEADS_SITE` is required; every other key has the default shown, and the fleet-shared values are what you'll typically set:

```
# Identity
LEADS_SITE=elect-club            # required — stamped on every lead row, scopes reads to this site
LEADS_SCHEMA_OWNER=false         # true on exactly one site in the fleet

# Shared leads database (registered as the `leads` connection)
LEADS_DB_CONNECTION=leads        # connection name the model + migrations use
LEADS_DB_HOST=127.0.0.1
LEADS_DB_PORT=3306
LEADS_DB_DATABASE=leads_shared
LEADS_DB_USERNAME=forge          # falls back to DB_USERNAME
LEADS_DB_PASSWORD=               # falls back to DB_PASSWORD
LEADS_DB_SOCKET=                 # falls back to DB_SOCKET

# Duo CRM
LEADS_DUO_URL=https://myduo.app/v1/lead/create

# Dispatch behaviour (see "Delivery to Duo" below)
LEADS_MAX_ATTEMPTS=5             # dispatch retries before a lead is marked failed
LEADS_DRAFT_TIMEOUT=600          # seconds before an abandoned draft is finalised by the admin cron

# Per-site Duo defaults
LEADS_OFFICE_ID=21
```

Facebook CAPI access tokens (`FB_*_TOKEN`, and `FB_*_TEST_CODE` for staging) are only needed on the app that delivers to Facebook — typically the admin app. See [Facebook CAPI credentials](#facebook-capi-credentials-admin-app) below.

Publish the config:

```bash
php artisan vendor:publish --tag=leads-config
```

Run the schema migration. Only the designated schema-owner site (`LEADS_SCHEMA_OWNER=true`) executes this — other sites in the fleet are blocked from running it. The command targets the `leads` connection so the migration record lands in the shared database itself:

```bash
php artisan leads:migrate            # run pending migrations
php artisan leads:migrate --pretend  # show SQL without executing
php artisan leads:migrate --rollback # roll back the last batch
```

## Usage

```php
use mojosef\Leads\LeadPipeline;

class PpcContactForm extends Component
{
    public function submit(LeadPipeline $pipeline)
    {
        $this->validate();

        $lead = $pipeline->start('ppc_contact', [
            'office_id' => 17, // optional — overrides the per-form / global default
        ]);

        $lead = $pipeline->complete($lead, [
            'fname' => $this->name,
            'email' => $this->email,
            'contact' => $this->contact,
            'town' => $this->town,
            'occupation' => $this->occupation,
            'contact_time' => $this->contact_time,
            'message' => $this->message,
        ]);

        return redirect('/thank-you')->with([
            'fb_lead' => $lead->isFacebookEligible(),
            'fb_event_id' => $lead->fb_event_id,
        ]);
    }
}
```

Multi-step:

```php
$lead = $pipeline->currentDraft('membership_apply') ?? $pipeline->start('membership_apply');
$pipeline->update($lead, ['fname' => $this->name, 'email' => $this->email]);
// ...later step...
$pipeline->complete($lead, ['occupation' => $this->occupation]);
```

## Contact form

The package centrally owns the fleet's contact-form structure: canonical field names, canonical answer values, validation, and the CRM payload shape. Sites customise **wording and composition only** — never the submitted values.

### What the package fixes

- **Field names** — the `mojosef\Leads\Enums\Question` string-backed enum: `age_bracket`, `town`, `marital_status`, `search_goal`, `dating_challenges`, `meet_timeline`, `investment_range`, `support_level`, `first_name`, `email`, `phone_number`.
- **Answer values** — one string-backed enum per fixed answer set (`AgeBracket`, `MaritalStatus`, `SearchGoal`, `DatingChallenge`, `MeetTimeline`, `InvestmentRange`, `SupportLevel`), e.g. `age_30_39`, `gbp_4000_7999`, `unsure`.

These backed values are a **permanent contract** between the sites, the package, Duo and analytics. Visible labels may be rebranded per site; the backed values must never be changed.

### Consuming the fields

The package ships no views — each site renders the form however it likes (Blade, Livewire, a JS front end) by iterating the definition. Use `Question->value` for input names, the answer enum values for option/checkbox values, and `label()` for visible wording:

```blade
@foreach (app(\mojosef\Leads\ContactForm\FormDefinition::class)->questions() as $question)
    <label for="{{ $question->value }}">{{ $question->label() }}</label>

    @if ($question->inputType() === \mojosef\Leads\Enums\InputType::Select)
        <select name="{{ $question->value }}" id="{{ $question->value }}">
            @foreach ($question->answerEnum()::cases() as $answer)
                <option value="{{ $answer->value }}">{{ $answer->label() }}</option>
            @endforeach
        </select>
    @elseif ($question->inputType() === \mojosef\Leads\Enums\InputType::Checkbox)
        @foreach ($question->answerEnum()::cases() as $answer)
            <label>
                <input type="checkbox" name="{{ $question->value }}[]" value="{{ $answer->value }}">
                {{ $answer->label() }}
            </label>
        @endforeach
    @else
        <input type="{{ $question->inputType()->value }}" name="{{ $question->value }}" id="{{ $question->value }}">
    @endif
@endforeach
```

`FormDefinition::questions()` returns the enabled questions in configured order; `isRequired()` tells you whether to mark a field required. Checkbox questions (`dating_challenges`) must submit as arrays (`name="dating_challenges[]"`). However the markup is built, the submitted names and values must be the canonical enum values — validation rejects anything else.

### Overriding wording per site

Publish the defaults (or just create the override file — partial overrides merge over the package defaults):

```bash
php artisan vendor:publish --tag=leads-translations
```

Then edit `lang/vendor/contact-form/en/form.php`:

```php
return [
    'age_bracket' => [
        'question' => 'Which age range are you in?',
        'answers' => [
            'age_under_30' => 'I’m under 30',
            'age_30_39' => 'Between 30 and 39',
        ],
    ],
];
```

The rendered wording changes; the submitted values remain `age_under_30` and `age_30_39`. Validation messages live in `lang/vendor/contact-form/en/validation.php`. All files are UTF-8, so `£` and en dashes are safe.

### Composition per site

`config/leads.php → contact_form` controls presentation concerns only, and **every key is an optional override**. Sites whose published config predates this section need to change nothing: with no `contact_form` config at all, every question is enabled and required, ordering follows the enum declaration order, and the default CRM property mapping (`first_name → fname`, `phone_number → contact`) applies — those defaults live in package code, so a partial config section can't accidentally drop them. Only add what you want to change:

```php
'contact_form' => [
    'questions' => [
        'support_level' => ['required' => false],
        'town' => ['enabled' => false],
        'email' => ['order' => 1],   // only relevant if you iterate FormDefinition::questions()
    ],
    'crm_properties' => [
        'age_bracket' => 'age_range', // rename the CRM property, value untouched
    ],
],
```

`order` is ignorable — it only affects sites that render by iterating `FormDefinition::questions()`; templates that hardcode their field layout can skip it entirely. The `form_schema_version` is owned by the package (`FormDefinition::SCHEMA_VERSION`), not config, so a site can't pin or drift it.

Config cannot redefine canonical enum values — only enable/disable, reorder, relax `required`, and map CRM property names.

### Validation and submission

```php
use mojosef\Leads\ContactForm\CrmMapper;
use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\LeadPipeline;

$validated = app(FormValidator::class)->validate($request->all());

$lead = $pipeline->start('contact');
$pipeline->complete($lead, app(CrmMapper::class)->map($validated));
```

`FormValidator` uses `Rule::enum()` for every fixed-choice answer (and per-element on checkbox arrays, with `distinct`), so an unknown or translated value is rejected before it can reach the pipeline. Unanswered optional questions are omitted from the payload — never coerced — so `null` and a real canonical answer like `unsure` stay distinct.

### Livewire / stepped forms

Don't hardcode `in:` lists in `#[Validate]` attributes — delegate the whole rule set to the package so every site validates against the same canonical values. Livewire `Form` objects can source `rules()`, `messages()` and `validationAttributes()` from `FormValidator`, and `rulesFor()` returns the rules for one step at a time (including the `dating_challenges.*` element rules, and throwing on a typo'd field name):

```php
use mojosef\Leads\ContactForm\CrmMapper;
use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;
use Livewire\Form;

class SteppedPaidSearchForm extends Form
{
    /** Field names are the canonical Question enum values. */
    protected array $stepFields = [
        1 => ['age_bracket', 'town', 'marital_status'],
        2 => ['search_goal', 'dating_challenges', 'meet_timeline'],
        3 => ['investment_range', 'support_level'],
        4 => ['first_name', 'email', 'phone_number'],
    ];

    public $age_bracket;
    public $town;
    public $marital_status;
    public $search_goal;
    public array $dating_challenges = [];
    public $meet_timeline;
    public $investment_range;
    public $support_level;
    public $first_name;
    public $email;
    public $phone_number;

    public function rules(): array
    {
        return app(FormValidator::class)->rules();
    }

    public function messages(): array
    {
        return app(FormValidator::class)->messages();
    }

    public function validationAttributes(): array
    {
        return app(FormValidator::class)->attributes();
    }

    public function validateStep(int $step): void
    {
        $this->validate(app(FormValidator::class)->rulesFor(...$this->stepFields[$step] ?? []));
    }

    public function save(): Lead
    {
        $validated = $this->validate();
        $pipeline = app(LeadPipeline::class);

        $lead = $pipeline->start('paid_search');

        return $pipeline->scheduleCompletion($lead, data: app(CrmMapper::class)->map($validated));
    }
}
```

A site may tighten the free-text fields locally (`$rules['first_name'][] = 'min:3';` inside `rules()`) — but never widen or replace the enum rules on fixed-choice fields.

### CRM payload shape

`CrmMapper::map()` emits canonical values only, keyed by the (optionally re-mapped) property names, plus a schema version:

```json
{
    "form_schema_version": 1,
    "age_bracket": "age_30_39",
    "town": "Harrogate",
    "marital_status": "divorced",
    "search_goal": "marriage",
    "dating_challenges": ["limited_time", "poor_match_quality"],
    "meet_timeline": "within_6_months",
    "investment_range": "gbp_4000_7999",
    "support_level": "unsure",
    "fname": "Alex",
    "email": "alex@example.com",
    "contact": "+44 7700 900123"
}
```

Two sites with completely different branded wording send byte-identical payloads for the same answers. No CRM payload ever contains a translated display label.

## Delivery to Duo

Frontend sites never deliver leads themselves. They only ever write rows to the shared database:

- `LeadPipeline::complete()` promotes the lead `draft → pending`.
- Multi-step forms call `update()` / `append()` (and optionally `scheduleCompletion()`) and leave the lead as `draft`.

A separate app — typically the leads-admin — owns delivery via three crons (see [Cron schedule](#cron-schedule)). Delivery to Duo and Facebook is entirely cron-driven, so no site in the fleet needs a queue worker.

```bash
php artisan leads:dispatch-pending          # ship pending leads to Duo, all sites
php artisan leads:dispatch-pending --site=elect-club --limit=20
php artisan leads:dispatch-pending --dry-run
```

### Facebook CAPI credentials (admin app)

When the admin app processes leads for multiple brands, each Lead's `site` column is used to look up the right Facebook credentials. Configure in `config/leads.php`:

```php
'facebook' => [
    'sites' => [
        'elect-club' => [
            'pixel_id' => '1124100455670536',
            'access_token' => env('FB_ELECT_CLUB_TOKEN'),
            'test_code' => env('FB_ELECT_CLUB_TEST_CODE'),
        ],
        'attractive-partners' => [
            'pixel_id' => '...',
            'access_token' => env('FB_ATTRACTIVE_PARTNERS_TOKEN'),
        ],
        // ... one entry per brand
    ],
],
```

Pixel IDs are not secret — hardcode them. Access tokens are — `env()` them. Test codes only matter for staging.

If no per-site entry exists, the service falls back to the global `conversions-api.*` config (used by single-tenant frontends until they're switched to admin-app delivery).

### Browser pixel deduplication

The package fires the **server-side** CAPI `Lead` event from the admin app's `leads:dispatch-facebook` cron. For it to deduplicate against the **browser** pixel `Lead` event — rather than Meta counting both and double-reporting the conversion — the frontend must fire its pixel with the *same* event id. That id is the Lead's `fb_event_id`, which the `complete()` example above flashes to the thank-you page.

A lead only fires the Facebook `Lead` event (browser pixel *and* server CAPI) when it is **Facebook-eligible** — i.e. it carried Facebook click attribution (an `fbclid`, or the `_fbc` cookie derived from one) at creation. Leads from Google, organic, or direct traffic send nothing to Meta. `isFacebookEligible()` reflects this; eligibility is decided once in `LeadPipeline::start()` and frozen onto the row, so the admin app sending the CAPI event and the frontend deciding whether to fire the pixel always agree — there is no per-form flag or cross-app config to keep in sync.

```blade
{{-- Thank-you page. Only fire when the lead was Facebook-eligible. --}}
@if (session('fb_lead'))
    <script>
        fbq('track', 'Lead', {}, { eventID: @json(session('fb_event_id')) });
    </script>
@endif
```

The contract the package guarantees on the server side, which the browser event must match for dedup to fire:

| Field        | Value                                  |
|--------------|----------------------------------------|
| `event_name` | `Lead`                                 |
| `eventID`    | the Lead's `fb_event_id` (a ULID)      |

Firing the pixel itself — which pixel id, consent/CMP gating, server-rendered page vs. AJAX response — is the frontend's call; this package deliberately ships no JS. The only thing each site must ensure is that `fb_event_id` survives from the submission request to wherever the pixel fires (flash, redirect, or the JSON response body all work). Phone numbers, emails, and names are normalized and hashed server-side by the CAPI SDK — never send hashed values from the browser.

### Cron schedule

Schedule all three delivery commands on a per-minute cron:

```php
// In the leads-admin app's app/Console/Kernel.php
$schedule->command('leads:dispatch-pending')->everyMinute()->withoutOverlapping();
$schedule->command('leads:finalize-drafts')->everyMinute()->withoutOverlapping();
$schedule->command('leads:dispatch-facebook --since=7d')->everyMinute()->withoutOverlapping();
```

`leads:finalize-drafts` handles the multi-step timeout — drafts older than `LEADS_DRAFT_TIMEOUT` (default 600s) are promoted to `pending` automatically so abandoned PaidSearchForm submissions still reach Duo.

`leads:dispatch-facebook` sweeps leads that reached Duo (`status = sent`) and are Facebook-eligible but not yet synced, and fires the CAPI `Lead` event for each. Because it works off `fb_synced_at IS NULL` rather than a queue, a send that fails or is missed is simply retried on the next tick — there is no job to lose. The `--since=7d` bound keeps it from re-attempting leads that have aged past Meta's 7-day acceptance window; such leads fall out of the sweep instead of being retried forever.

Because delivery is entirely cron-driven, **no queue workers are needed anywhere in the fleet.** Duo delivery uses the `LeadDispatcher` service with backoff (`leads.dispatch.backoff`) and max attempts (`leads.dispatch.max_attempts`); its atomic claim (`UPDATE ... WHERE status='pending'`) means a lead is never sent to Duo twice even if two crons overlap.

### Monitoring

Cron-driven delivery has one failure mode worth watching: if a cron stops, leads pile up silently in a transient state, and Facebook-eligible leads that sit unsynced for more than 7 days age out of the `dispatch-facebook` sweep permanently. `leads:health` is the smoke detector — schedule it and surface failures:

```php
$schedule->command('leads:health')->everyFifteenMinutes()->emailOutputOnFailure('ops@example.com');
```

It counts leads stuck in `draft` / `pending` / `sending`, and Facebook-eligible leads still unsynced, beyond `--warn-hours` (default 48). A running pipeline drives all of these to zero, so a non-zero count means a cron is behind — and the command **exits non-zero**, which is what `emailOutputOnFailure()` (or any external monitor checking the exit code) keys off. Terminal states (FB events past the 7-day window, Duo leads that exhausted their retries) are reported but don't trip the exit code, since they won't self-heal and would otherwise alarm forever.

```bash
php artisan leads:health                 # human-readable table, all sites
php artisan leads:health --warn-hours=12 # tighter staleness window
php artisan leads:health --site=ec
php artisan leads:health --json          # for piping to a monitor (has an `alert` field)
```

## Ops

Re-dispatch failed leads:

```bash
php artisan leads:resend --status=failed --since=24h --dry-run
php artisan leads:resend --site='*' --status=failed
php artisan leads:resend --id=01HXYZ...
```

`leads:dispatch-facebook` is normally on the per-minute cron (above), but it's also the manual tool for backfills or targeted re-sends — it sweeps eligible leads sent to Duo (`fb_eligible = 1`) whose `fb_synced_at` is still null. Sends in-process so each attempt's result is printed:

```bash
php artisan leads:dispatch-facebook --dry-run            # list candidates, all sites
php artisan leads:dispatch-facebook --since=7d           # backfill the last week
php artisan leads:dispatch-facebook --site=ec --limit=50
php artisan leads:dispatch-facebook --id=01HXYZ...       # specific leads, ignores filters
```

Each lead reports `sent` (with the CAPI `events_received` / `fbtrace`), `skipped` (no credentials for the site — left retryable), or `failed` (the error is printed and written to the lead's `fb_response` without marking it synced, so the next run picks it up again). Note: Meta rejects `Lead` events older than 7 days, so scope backfills with `--since`.
