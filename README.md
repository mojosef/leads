# mojosef/leads

Durable, queue-backed lead pipeline shared across the Duo CRM site fleet. Every lead is persisted to a row in the shared `leads` database before any outbound HTTP, so a Duo or Facebook outage no longer loses revenue. Multi-step forms become a side-effect of having a draft row to update.

## Installation

```bash
composer require mojosef/leads
```

The service provider auto-registers the shared `leads` database, Redis, and queue connections from the env keys below — no `config/database.php` or `config/queue.php` edits are needed. Each registration is guarded: if the host app already defines a connection of the same name, that one wins.

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

# Shared Redis (registered as the `leads` redis connection)
LEADS_REDIS_URL=
LEADS_REDIS_HOST=127.0.0.1       # falls back to REDIS_HOST
LEADS_REDIS_PASSWORD=            # falls back to REDIS_PASSWORD
LEADS_REDIS_PORT=6379            # falls back to REDIS_PORT
LEADS_REDIS_DB=5
LEADS_REDIS_PREFIX=fleet_leads_  # all sites share one keyspace — keep identical across the fleet

# Queue (registered as the `leads` queue connection)
LEADS_QUEUE_CONNECTION=leads     # connection package jobs route onto; unset = host's default queue
LEADS_QUEUE_NAME=leads           # queue name within that connection; workers must include it in --queue=

# Duo CRM
LEADS_DUO_URL=https://myduo.app/v1/lead/create

# Dispatch behaviour (see "Delivery to Duo" below)
LEADS_AUTO_DISPATCH_JOB=true     # false = leave leads pending for the admin app's cron
LEADS_MAX_ATTEMPTS=5
LEADS_DRAFT_TIMEOUT=600          # seconds before a multi-step draft is auto-finalised

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

## Delivery to Duo

Two modes — pick one per site, both can coexist across the fleet:

### Job mode (default — for sites with queue workers)

`LEADS_AUTO_DISPATCH_JOB=true` (or unset). `LeadPipeline::complete()` dispatches `SendLeadToDuoJob` immediately. Horizon processes it. Lowest latency for live forms.

### Command mode (for the leads-admin app or sites without queue workers)

`LEADS_AUTO_DISPATCH_JOB=false`. `complete()` just writes the row as `pending` — no queue dispatch. A separate app (typically the leads-admin) runs:

```bash
php artisan leads:dispatch-pending          # processes all sites
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

The package fires the **server-side** CAPI `Lead` event from `SendLeadToFacebookJob`. For it to deduplicate against the **browser** pixel `Lead` event — rather than Meta counting both and double-reporting the conversion — the frontend must fire its pixel with the *same* event id. That id is the Lead's `fb_event_id`, which the `complete()` example above flashes to the thank-you page:

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

Schedule both delivery commands on a per-minute cron:

```php
// In the leads-admin app's app/Console/Kernel.php
$schedule->command('leads:dispatch-pending')->everyMinute()->withoutOverlapping();
$schedule->command('leads:finalize-drafts')->everyMinute()->withoutOverlapping();
```

`leads:finalize-drafts` handles the multi-step timeout — drafts older than `LEADS_DRAFT_TIMEOUT` (default 600s) are completed automatically so abandoned PaidSearchForm submissions still reach Duo. With `LEADS_AUTO_DISPATCH_JOB=false` on the frontend sites, **no queue workers are needed anywhere except possibly the admin app**.

Both modes use the same `LeadDispatcher` service. Backoff (`leads.dispatch.backoff`) and max attempts (`leads.dispatch.max_attempts`) apply identically. Atomic claim (`UPDATE ... WHERE status='pending'`) means a lead is never sent twice even if both a queue worker and the cron tried to grab it.

## Ops

Re-dispatch failed leads:

```bash
php artisan leads:resend --status=failed --since=24h --dry-run
php artisan leads:resend --site='*' --status=failed
php artisan leads:resend --id=01HXYZ...
```
