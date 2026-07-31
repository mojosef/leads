# Changelog

All notable changes will be documented here.

## [Unreleased]

### Removed — draft leads
- **BREAKING: draft leads are gone.** `LeadPipeline::start()` no longer inserts a row — it builds the Lead in memory (attribution snapshot, ULID `id`, `event_id`, `fb_eligible`) and `complete()` performs the one and only insert, directly as `pending`. Abandoned forms write nothing; repeated `start()` calls create no duplicate rows. `created_at` now records submission time rather than form-start time. Calling `complete()` on an already-persisted lead is a logged no-op (double-clicked submits can't create a second row). Because the unsaved Lead cannot survive a request boundary (e.g. a Livewire hydration cycle), `start()` and `complete()` must run in the same request — as every documented pattern already does.
- **Deleted:** the `leads:finalize-drafts` command, `LeadPipeline::update()` / `scheduleCompletion()` / `currentDraft()` / `append()`, the session draft storage (`leads.draft.{formKey}`), and the `draft_timeout_seconds` config key / `LEADS_DRAFT_TIMEOUT` env key. `Lead::STATUS_DRAFT` is retained read-only so fleet apps can still interpret legacy draft rows in the shared database.
- **Changed:** `leads:health` no longer reports drafts and now exit-gates on stale **pending** rows (Duo ingestion behind) instead of stuck drafts. JSON contract change: `stuck.drafts_stuck` → `stuck.pending_stale`; `totals` now contains only `pending_total`.
- **Migration:**
  1. Before upgrading a fleet site, grep it for removed pipeline methods: `scheduleCompletion|currentDraft|->append(` and pipeline `->update(` calls — they now fatal with `Call to undefined method`. The standard `start()` + `complete()` submit flow needs no changes.
  2. Run `leads:finalize-drafts` one final time (or upgrade leads-admin last) so in-flight drafts created by older package versions get promoted rather than stranded, then remove its cron entry.
  3. Drop `LEADS_DRAFT_TIMEOUT` from every site's env.
  4. Update any monitor parsing `leads:health --json` for the new `stuck`/`totals` shape.

### Added
- `leads:health` command reports lead-pipeline backlog counts and is built for alerting: it **exits non-zero** when drafts are stuck beyond `--warn-hours` (default 48) — the signal that the `leads:finalize-drafts` cron has stopped or fallen behind. The `pending` backlog (Duo's ingestion queue, including a stale-pending count) is reported for visibility but doesn't drive the exit code, since clearing it is Duo's responsibility. Supports `--site`, `--warn-hours`, and `--json` (with an `alert` field) for external monitors. Schedule it with `->emailOutputOnFailure()`.

### Removed
- **BREAKING:** the package no longer owns the shared database schema — Duo does. Deleted: the `leads:migrate` command, the package migrations (`create_leads_table`, `rename_fb_event_id_to_event_id`), the `leads.schema_owner` config key, and the `LEADS_SCHEMA_OWNER` env key. The package still registers the `leads` connection and reads/writes the `leads` table; creating and altering that table is Duo's job. **Migration:** drop `LEADS_SCHEMA_OWNER` from every site's env. The shared database's `migrations` table records for the package migrations can be left in place or cleaned up by Duo — nothing reads them anymore.
- **BREAKING:** the package no longer delivers leads to Duo — Duo ingests leads by reading the shared database directly, so the HTTP handoff is gone. Deleted: `LeadDispatcher`, the `leads:dispatch-pending` and `leads:resend` commands, `LeadPipeline::resend()`, `Lead::buildDuoPayload()` / `markSending()` / `markSent()` / `markFailed()` / `incrementAttempts()`, the `leads.duo` and `leads.dispatch` config blocks, the `LEADS_DUO_URL` / `LEADS_MAX_ATTEMPTS` env keys, and the `guzzlehttp/guzzle` + `illuminate/queue` dependencies. The package now only ever writes `draft` and `pending` rows; every later status transition (`sending` / `sent` / `failed` / `discarded`) and all ingestion retries belong to Duo. The Duo-side columns (`sent_to_duo_at`, `duo_http_status`, `duo_response_*`, `attempts`, `last_error*`) remain in the schema for Duo and older package versions to use. **Migration:** remove the `leads:dispatch-pending` cron entry and drop the `LEADS_DUO_URL` / `LEADS_MAX_ATTEMPTS` env keys; only `leads:finalize-drafts` (plus `leads:health`) still needs scheduling.
- **BREAKING:** the package no longer sends Facebook CAPI events at all — Duo (the CRM) owns server-side conversion delivery. Deleted: `FacebookLeadService`, the `leads:dispatch-facebook` command, `Lead::markFbSynced()` / `Lead::markFbFailed()`, the `leads.facebook` config block, and the `esign/laravel-conversions-api` + `giggsey/libphonenumber-for-php-lite` dependencies. The attribution side is untouched: `LeadPipeline` still snapshots cookies/attribution, freezes `fb_eligible`, and issues the platform-neutral `event_id`, and the lead row carries the click IDs (`_fbp` / `_fbc` / `gclid` / `_ga`), IP, user agent, and referrer — the data Duo's CAPI reads straight from the database. The `fb_synced_at` / `fb_response` columns remain in the schema (unwritten) so older package versions in the fleet keep working during rollout. **Migration:** remove any `leads:dispatch-facebook` cron entry and drop the `FB_*_TOKEN` / `FB_*_TEST_CODE` env keys; frontends keep flashing `event_id` + `isFacebookEligible()` for the browser pixel exactly as before.
- **BREAKING:** auto-dispatch removed. `SendLeadToDuoJob` and `FinalizeLeadJob` are deleted, along with the `LEADS_AUTO_DISPATCH_JOB` env / `leads.dispatch.auto_dispatch_job` config flag. Frontend sites no longer ship leads to Duo (or schedule their own finalisation) from the request cycle. **Migration:** drop `LEADS_AUTO_DISPATCH_JOB` from every site's env, and ensure the admin app schedules `leads:finalize-drafts` on a per-minute cron. Sites that previously relied on `auto_dispatch_job=true` no longer need a Duo queue worker.
- **BREAKING:** `SendLeadToFacebookJob` is deleted and the package no longer queues anything. Removed with it: the `leads.queue` config block, the auto-registered `queue.connections.leads` and `database.redis.leads` connections, and the `LEADS_QUEUE_CONNECTION` / `LEADS_QUEUE_NAME` / `LEADS_REDIS_*` env keys. **Migration:** every site can drop the `LEADS_QUEUE_*` and `LEADS_REDIS_*` env keys (leftover values are simply ignored). No site in the fleet needs a queue worker anymore.

### Changed
- **BREAKING:** the `fb_event_id` column is renamed to `event_id` — the token is platform-neutral, so the same value can be sent as the Meta CAPI/pixel `event_id` *and* as the Google Ads `transaction_id` / `order_id` for conversion dedup. The rename is a Duo-owned schema change (the package no longer ships migrations); the code reads/writes `event_id` and a deprecated `fb_event_id` accessor on `Lead` keeps existing `$lead->fb_event_id` reads working during rollout. **Migration:** ensure Duo has applied the column rename, then update each site's thank-you flow to flash/read `event_id` instead of `fb_event_id`; the accessor will be removed in a future release.
- `LeadPipeline::complete()` now only promotes a lead `draft → pending` — it no longer dispatches `SendLeadToDuoJob`. The lead sits as `pending` until Duo's next read of the shared database picks it up.
- `LeadPipeline::scheduleCompletion()` no longer dispatches a delayed `FinalizeLeadJob`; it only persists any supplied data and leaves the lead as `draft`. Abandoned multi-step drafts are now finalised solely by the admin app's `leads:finalize-drafts` cron once they exceed `draft_timeout_seconds`. The `$delaySeconds` argument is retained for call-site compatibility but is now ignored.
- Facebook eligibility is now driven solely by real Facebook click attribution. A lead counts as Facebook-eligible only when it carried an `fbclid` (or the derived `_fbc` cookie) at creation; the decision is computed once in `LeadPipeline::start()` and frozen onto the `fb_eligible` column, which frontends read via `isFacebookEligible()` to gate the browser pixel. Removed the per-form `leads.forms.*.fb_eligible` config flag and the config fallback in `Lead::isFacebookEligible()`, which had to be kept in sync between the frontend and admin apps and silently reported Google/organic/direct leads to Meta on mixed-channel landing pages. **Behaviour change:** forms previously relying on `fb_eligible => true` (e.g. `paid_search`, `ppc_contact`) no longer fire Facebook events for non-Facebook traffic. The `forms` config block remains for per-form `office_id` overrides.

## [0.1.5] - 2026-05-26

### Added
- Facebook CAPI phone numbers are normalized to E.164 before the SDK hashes them, improving Meta match quality. A bare UK `07123456789` previously hashed to a different value than Meta's stored `447123456789` and silently failed to match. `FacebookLeadService::normalizePhone()` uses the lead's Cloudflare `country_code` as the region hint (falling back to GB), validates, and formats to E.164; unparseable or invalid numbers pass through unchanged. Backed by the new `giggsey/libphonenumber-for-php-lite` dependency (core parse/validate/format metadata only — no geocoder/carrier/timezone data).
- README: **Browser pixel deduplication** section documenting the server/browser dedup contract (`event_name = Lead`, `eventID = fb_event_id`) and the thank-you-page `fbq` snippet, so consuming sites can match the CAPI event the package fires.

## [0.1.4] - 2026-05-26

### Added
- `CaptureAttribution` now stores any non-standard query-string param (one that isn't a known UTM tag or click ID) under a nested `attribution.query` key — e.g. a `variants` param carrying template data. Kept separate so user-supplied params can't clobber reserved top-level keys, and capped (25 params, 500 chars each) to keep the session and lead row from bloating.

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
