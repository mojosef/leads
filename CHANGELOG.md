# Changelog

All notable changes will be documented here.

## [Unreleased]

### Added
- Initial release. Lead model + pipeline, SendLeadToDuoJob, SendLeadToFacebookJob, ResendFailedLeadsCommand, CaptureAttribution middleware. Shared-DB schema with `site` column and global SiteScope.
- `leads:migrate` artisan command: schema-owner-gated wrapper that runs the package's migrations against the `leads` connection so the migration record lives in the shared database alongside the table.
- Multi-step delayed-completion pattern: `LeadPipeline::scheduleCompletion()`, `FinalizeLeadJob`, `appendQuestionnaire()`. Forms with follow-on pages create a draft Lead, write any data we have, and let either the follow-on submission or a timeout finalise the row — Duo always sees one `/lead/create` per user. The legacy `App\Jobs\SendQuestionnaireToDuo` flow is replaced.
- `LeadDispatcher` service: extracts the Duo HTTP call so it's reusable from both queue jobs and scheduled commands. Atomic pending→sending claim prevents double-sends.
- `leads:dispatch-pending` artisan command: cron-driven alternative to queue-based dispatch. Lets the leads-admin app pull pending leads from the shared database without each frontend site needing a queue worker. Set `LEADS_AUTO_DISPATCH_JOB=false` on frontends to delegate delivery to the admin app.
