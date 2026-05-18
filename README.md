# mojosef/leads

Durable, queue-backed lead pipeline shared across the Duo CRM site fleet. Every lead is persisted to a row in the shared `leads` database before any outbound HTTP, so a Duo or Facebook outage no longer loses revenue. Multi-step forms become a side-effect of having a draft row to update.

## Installation

```bash
composer require mojosef/leads
```

Add a `leads` connection to `config/database.php` and the following env keys to each consuming site:

```
LEADS_SITE=elect-club
LEADS_SCHEMA_OWNER=false   # true on exactly one site in the fleet

LEADS_DB_CONNECTION=leads
LEADS_DUO_URL=https://myduo.app/v1/lead/create

LEADS_PROSPECT_QUEUE_ID=5
LEADS_OFFICE_ID=21
LEADS_FB_SOURCE_IDS=59
```

Publish the config:

```bash
php artisan vendor:publish --tag=leads-config
```

Migrations are auto-loaded only when `LEADS_SCHEMA_OWNER=true`, so only one site in the fleet runs `php artisan migrate` against the shared database.

## Usage

```php
use mojosef\Leads\LeadPipeline;

class PpcContactForm extends Component
{
    public function submit(LeadPipeline $pipeline)
    {
        $this->validate();

        $lead = $pipeline->start('ppc_contact', [
            'lead_source_id' => $this->lead_source_id,
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

## Ops

Re-dispatch failed leads:

```bash
php artisan leads:resend --status=failed --since=24h --dry-run
php artisan leads:resend --site='*' --status=failed
php artisan leads:resend --id=01HXYZ...
```
