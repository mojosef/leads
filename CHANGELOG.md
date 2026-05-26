# Changelog

All notable changes will be documented here.

## [Unreleased]

### Added
- Facebook CAPI phone numbers are normalized to E.164 before the SDK hashes them, improving Meta match quality. A bare UK `07123456789` previously hashed to a different value than Meta's stored `447123456789` and silently failed to match. `FacebookLeadService::normalizePhone()` uses the lead's Cloudflare `country_code` as the region hint (falling back to GB), validates, and formats to E.164; unparseable or invalid numbers pass through unchanged. Backed by the new `giggsey/libphonenumber-for-php-lite` dependency (core parse/validate/format metadata only — no geocoder/carrier/timezone data).

## [0.1.3] - 2026-05-26

### Changed
- **BREAKING** (small surface area): `LeadPipeline::appendQuestionnaire(Lead, array)` replaced with generic `LeadPipeline::append(Lead, string $section, array $data)`. Any follow-on component can now contribute data to a draft lead under its own named section — not just a hard-coded questionnaire path. Idempotency is per-section via `payload[$section.'_completed_at']`. Consuming code: `appendQuestionnaire($lead, $answers)` → `append($lead, 'questionnaire', $answers)`.
- `Lead::isQuestionnaireCompleted()` replaced with the generic `Lead::hasCompletedSection(string $section): bool`.
- `append()` no longer auto-completes a draft lead and no longer dispatches an append job. The lead stays as `draft` until either an explicit `complete()` call or the timeout fires. Completion is now uniformly timeout-driven, so Duo always receives the lead as a single `/lead/create` with the full accumulated payload — there's no longer a code path where the user finishes a follow-on form and Duo gets an immediate create.

### Removed
- `AppendLeadToDuoJob` deleted. The system no longer calls Duo's `/lead/append` endpoint under any circumstance. Whatever data the draft accumulates before the timeout is the entirety of what Duo ever sees about the lead. This removes the "user submits follow-on form after timeout fired → /lead/append fallback" path that was previously a rare edge case.

## [0.1.2] - 2026-05-19

### Fixed
- `FinalizeLeadJob` no longer finalises a draft lead before the configured `draft_timeout_seconds` has elapsed. On a `sync` queue driver, Laravel ignores `dispatch()->delay()` and runs jobs immediately — the previous behaviour was to complete the draft straight away on dispatch, defeating the multi-step pattern. The job now re-checks elapsed time and no-ops if the timeout has not yet passed; on async queues the behaviour is unchanged because the job arrives after the delay. On sync queues the draft is finalised later by `leads:finalize-drafts` (or by the follow-on form completing it).

## [0.1.1] - 2026-05-19

### Changed
- Widened `illuminate/*` version constraints to `^11.0||^12.0||^13.0` so the package installs on Laravel 13 hosts.

## [0.1.0] - 2026-05-19

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
