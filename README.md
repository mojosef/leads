# mojosef/leads

Durable lead pipeline shared across the Duo CRM site fleet. Every submitted lead is written as a single `pending` row in the shared `leads` database, which Duo reads directly to ingest leads — the package makes no outbound HTTP at all, so nothing downstream being slow or down can lose a lead. An abandoned form writes nothing. Server-side ad-platform events (Meta CAPI, Google offline conversions) are Duo's job too — the package captures the attribution Duo needs and freezes it onto the row.

## Installation

```bash
composer require mojosef/leads
```

The service provider auto-registers the shared `leads` database connection from the env keys below — no `config/database.php` edits are needed. The registration is guarded: if the host app already defines a connection of the same name, that one wins. The package queues nothing, so no Redis or queue connection is registered.

Add the following env keys to each consuming site. Only `LEADS_SITE` is required; every other key has the default shown, and the fleet-shared values are what you'll typically set:

```
# Identity
LEADS_SITE=elect-club            # required — stamped on every lead row, scopes reads to this site

# Shared leads database (registered as the `leads` connection)
LEADS_DB_CONNECTION=leads        # connection name the Lead model uses
LEADS_DB_HOST=127.0.0.1
LEADS_DB_PORT=3306
LEADS_DB_DATABASE=leads_shared
LEADS_DB_USERNAME=forge          # falls back to DB_USERNAME
LEADS_DB_PASSWORD=               # falls back to DB_PASSWORD
LEADS_DB_SOCKET=                 # falls back to DB_SOCKET

# Per-site Duo defaults
LEADS_OFFICE_ID=21
```

Publish the config:

```bash
php artisan vendor:publish --tag=leads-config
```

The package ships no migrations — Duo owns the shared database schema (the `leads` table and any changes to it). Sites just read and write it through the registered connection.

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
            'event_id' => $lead->event_id,
        ]);
    }
}
```

`start()` builds the Lead in memory — it snapshots attribution/cookies and assigns the `id`, `event_id` and `fb_eligible` up front — but persists nothing. `complete()` performs the single `pending` insert, so an abandoned form leaves no row.

Multi-step forms hold their answers in component/session state and call `start()` + `complete()` together in the final submit. The unsaved Lead returned by `start()` cannot round-trip a Livewire hydration cycle, so both calls must happen in the same request.

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

Don't hardcode `in:` lists in `#[Validate]` attributes — use the `ValidatesContactForm` trait so every site validates against the same canonical values. It wires Livewire's `rules()`, `messages()` and `validationAttributes()` to the package's `FormValidator`, and adds `validateStep()` for multi-step forms (including the `dating_challenges.*` element rules; a typo'd field name in `stepFields()` throws instead of silently skipping validation):

```php
use mojosef\Leads\ContactForm\ValidatesContactForm;
use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;
use Livewire\Form;

class SteppedPaidSearchForm extends Form
{
    use ValidatesContactForm;

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

    /** Field names are the canonical Question enum values. */
    protected function stepFields(): array
    {
        return [
            1 => ['age_bracket', 'town', 'marital_status'],
            2 => ['search_goal', 'dating_challenges', 'meet_timeline'],
            3 => ['investment_range', 'support_level'],
            4 => ['first_name', 'email', 'phone_number'],
        ];
    }

    public function save(): Lead
    {
        $pipeline = app(LeadPipeline::class);

        return $pipeline->complete($pipeline->start('paid_search'), $this->validatedCrmPayload());
    }
}
```

Both pipeline methods return the `Lead`, so `save()` can pass it straight through — the calling component typically needs it for the thank-you redirect (`$lead->id` for a `?token=` parameter, `$lead->event_id` and `isFacebookEligible()` for browser-pixel dedup). If your component uses none of that, declare `save(): void` and drop the `return` instead.

Always hand the pipeline `validatedCrmPayload()` (or `CrmMapper::map($validated)`) — never the raw `$this->validate()` result. The raw data has unmapped keys (`first_name`, `phone_number`), so the Duo payload would use the wrong property names, the Lead's `fname`/`contact` columns would stay empty, and `form_schema_version` would be missing.

The component calls `$this->form->validateStep(2)` as the user advances. A `protected array $stepFields = [...]` property works too — the trait picks it up when `stepFields()` isn't overridden. `validateStep()` throws a `LogicException` if the step has no fields defined, so a misconfigured step can never silently let unvalidated data through. Single-step forms just `use ValidatesContactForm;` and ignore steps entirely. To tighten free-text fields locally, override `additionalRules()` (e.g. `return ['first_name' => ['min:3']];`) — it merges onto the package rules, so the canonical enum rules always remain in force.

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

## Handoff to Duo

The package never delivers leads anywhere. Sites only ever write rows to the shared database, and Duo reads that database directly to ingest new leads:

- `LeadPipeline::complete()` inserts the lead directly as `pending` — a single insert, no draft state.
- Duo picks up `pending` rows itself; every status transition past `pending` (`sending` / `sent` / `failed` / `discarded`) is Duo's, as are ingestion retries.

There are no fleet-side moving parts: no cron is required for lead flow, no site needs a queue worker, and no app makes outbound HTTP for leads.

### Ad-platform attribution (CAPI happens in Duo)

The package does **not** send any server-side ad-platform events. Duo owns Meta CAPI (and any other server-side conversion uploads); the package's job is to capture attribution at submission time onto the lead row. Each row carries the snapshotted `cookies` (`_fbp`, `_fbc`, `gclid`, `_ga`, …) and `attribution` JSON plus IP, user agent, referrer, and `country_code` — everything Duo needs to fire a well-matched CAPI event straight from the database.

### Browser pixel deduplication

Duo fires the **server-side** CAPI `Lead` event. For it to deduplicate against the **browser** pixel `Lead` event — rather than Meta counting both and double-reporting the conversion — the frontend must fire its pixel with the *same* event id. That id is the Lead's `event_id`, which the `complete()` example above flashes to the thank-you page. The token is platform-neutral: the same value should be used as the Google Ads `transaction_id` / `order_id` when a site fires a browser gtag conversion or uploads server-side conversions, so each platform dedupes its own pair independently.

A lead only fires the Facebook `Lead` event when it is **Facebook-eligible** — i.e. it carried Facebook click attribution (an `fbclid`, or the `_fbc` cookie derived from one) at creation. Leads from Google, organic, or direct traffic send nothing to Meta. `isFacebookEligible()` reflects this; eligibility is decided once in `LeadPipeline::start()` and frozen onto the row (`fb_eligible`), so the frontend deciding whether to fire the pixel and anything downstream reading the row always agree — there is no per-form flag or cross-app config to keep in sync.

```blade
{{-- Thank-you page. Only fire when the lead was Facebook-eligible. --}}
@if (session('fb_lead'))
    <script>
        fbq('track', 'Lead', {}, { eventID: @json(session('event_id')) });
    </script>
@endif
```

The contract the package guarantees on the server side, which the browser event must match for dedup to fire:

| Field        | Value                                  |
|--------------|----------------------------------------|
| `event_name` | `Lead`                                 |
| `eventID`    | the Lead's `event_id` (a ULID)         |

Firing the pixel itself — which pixel id, consent/CMP gating, server-rendered page vs. AJAX response — is the frontend's call; this package deliberately ships no JS. The only thing each site must ensure is that `event_id` survives from the submission request to wherever the pixel fires (flash, redirect, or the JSON response body all work). Never send hashed PII values from the browser — Duo normalizes and hashes server-side.

### Monitoring

If Duo stops ingesting, pending rows pile up silently in the shared database. `leads:health` is the smoke detector — schedule it (typically on the leads-admin app) and surface failures:

```php
$schedule->command('leads:health')->everyFifteenMinutes()->emailOutputOnFailure('ops@example.com');
```

It **exits non-zero** when pending rows are stuck beyond `--warn-hours` (default 48), which is what `emailOutputOnFailure()` (or any external monitor checking the exit code) keys off. The `--json` output reports the same counts under `stuck.pending_stale` / `totals.pending_total`, plus a top-level `alert` boolean.

```bash
php artisan leads:health                 # human-readable table, all sites
php artisan leads:health --warn-hours=12 # tighter staleness window
php artisan leads:health --site=ec
php artisan leads:health --json          # for piping to a monitor (has an `alert` field)
```
