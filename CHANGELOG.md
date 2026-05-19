# Changelog

All notable changes will be documented here.

## [Unreleased]

### Added
- Initial release. Lead model + pipeline, SendLeadToDuoJob, SendLeadToFacebookJob, ResendFailedLeadsCommand, CaptureAttribution middleware. Shared-DB schema with `site` column and global SiteScope.
- `leads:migrate` artisan command: schema-owner-gated wrapper that runs the package's migrations against the `leads` connection so the migration record lives in the shared database alongside the table.
- Multi-step delayed-completion pattern: `LeadPipeline::scheduleCompletion()`, `FinalizeLeadJob`, `appendQuestionnaire()`. Forms with follow-on pages create a draft Lead, write any data we have, and let either the follow-on submission or a timeout finalise the row — Duo always sees one `/lead/create` per user. The legacy `App\Jobs\SendQuestionnaireToDuo` flow is replaced.
- `LeadDispatcher` service: extracts the Duo HTTP call so it's reusable from both queue jobs and scheduled commands. Atomic pending→sending claim prevents double-sends.
- `leads:dispatch-pending` artisan command: cron-driven alternative to queue-based dispatch. Lets the leads-admin app pull pending leads from the shared database without each frontend site needing a queue worker. Set `LEADS_AUTO_DISPATCH_JOB=false` on frontends to delegate delivery to the admin app.
- `leads:finalize-drafts` artisan command: cron-driven alternative to `FinalizeLeadJob`. Completes draft leads whose multi-step timeout has elapsed so the admin app can fully replace queue workers on frontend sites.
- Per-site Facebook CAPI credentials: `config/leads.php` now has a `facebook.sites` map keyed by site identifier, each entry holding `pixel_id`, `access_token`, and optional `test_code`. `FacebookLeadService` looks up credentials per Lead's `site` column. Required for the admin app, which processes leads for multiple brands. Falls back to global `conversions-api.*` config for single-tenant backwards compatibility. SDK's global `Api` singleton is re-initialised per call so sequential leads from different sites each use their own credentials.

### Removed
- `lead_source_id` and `prospect_queue_id` columns dropped from the `leads` schema via the new `2026_05_19_drop_lead_source_and_queue_columns` migration. These were Duo routing values that are no longer required. Removed from `Lead` model, `LeadPipeline::start()` overrides, `buildDuoPayload()`, config defaults, and frontend forms.
- Facebook CAPI eligibility no longer consults `lead_source_id`. Instead, a per-form-key `fb_eligible` flag in `config/leads.php` controls whether a lead from that form fires CAPI by default. Fallback path (`fbclid` present → eligible) is unchanged. The package's bundled defaults set `forms.ppc_contact` and `forms.paid_search` to `fb_eligible = true`.
