# Changelog

All notable changes will be documented here.

## [Unreleased]

### Added
- `leads:health` command reports lead-pipeline backlog counts and is built for alerting: it **exits non-zero** when leads are stuck in a transient state (`draft` / `pending` / `sending`) or Facebook-eligible-but-unsynced beyond `--warn-hours` (default 48) — the signal that a delivery cron has stopped or fallen behind. Terminal states (FB events past Meta's 7-day window, Duo leads that exhausted retries) are reported but don't drive the exit code. Supports `--site`, `--warn-hours`, and `--json` (with an `alert` field) for external monitors. Schedule it with `->emailOutputOnFailure()`. Closes the observability gap that let a missed Facebook send sit unnoticed.
- `leads:dispatch-facebook` command is the sole delivery path for Facebook CAPI `Lead` events. Designed for the leads-admin app's per-minute cron (alongside `leads:dispatch-pending`), it sweeps Facebook-eligible leads that reached Duo (`status = sent`) but whose `fb_synced_at` is still null and fires the CAPI event for each, in-process. Because it works off `fb_synced_at IS NULL` rather than a queue, a failed or missed send is retried on the next tick — there is no job to lose. Supports `--id`, `--site`, `--since`, `--limit`, `--dry-run`, and doubles as the manual backfill / targeted re-send tool, printing each result (`sent` / `skipped` / `failed`). Failures are recorded to `fb_response` via the new `Lead::markFbFailed()` helper without setting `fb_synced_at`, so the lead stays in the sweep. Schedule with `--since=7d` so leads past Meta's 7-day acceptance window drop out of the sweep instead of being retried forever.

### Removed
- **BREAKING:** auto-dispatch removed. `SendLeadToDuoJob` and `FinalizeLeadJob` are deleted, along with the `LEADS_AUTO_DISPATCH_JOB` env / `leads.dispatch.auto_dispatch_job` config flag. Frontend sites no longer ship leads to Duo (or schedule their own finalisation) from the request cycle — delivery is now exclusively the leads-admin app's job. **Migration:** drop `LEADS_AUTO_DISPATCH_JOB` from every site's env, and ensure the admin app schedules `leads:dispatch-pending`, `leads:finalize-drafts`, and `leads:dispatch-facebook` on a per-minute cron. Sites that previously relied on `auto_dispatch_job=true` no longer need a Duo queue worker.
- **BREAKING:** `SendLeadToFacebookJob` is deleted and the package no longer queues anything. The Facebook CAPI event is delivered by the new `leads:dispatch-facebook` cron sweep instead of a queue job dispatched from `LeadDispatcher` after the Duo send. Removed with it: the `leads.queue` config block, the auto-registered `queue.connections.leads` and `database.redis.leads` connections, and the `LEADS_QUEUE_CONNECTION` / `LEADS_QUEUE_NAME` / `LEADS_REDIS_*` env keys. This closes a silent failure mode where a missed/lost job left an `fb_eligible` lead with `fb_synced_at` null and no trace on the row. **Migration:** the leads-admin app should stop its leads queue worker and schedule `leads:dispatch-facebook --since=7d` on the per-minute cron; every site can drop the `LEADS_QUEUE_*` and `LEADS_REDIS_*` env keys (leftover values are simply ignored). No site in the fleet needs a queue worker anymore.

### Changed
- **BREAKING:** the `fb_event_id` column is renamed to `event_id` — the token is platform-neutral, so the same value can be sent as the Meta CAPI/pixel `event_id` *and* as the Google Ads `transaction_id` / `order_id` for conversion dedup. A new migration renames the column in place (values are unchanged). A deprecated `fb_event_id` accessor on `Lead` keeps existing `$lead->fb_event_id` reads working during rollout. **Migration:** run `php artisan migrate`, then update each site's thank-you flow to flash/read `event_id` instead of `fb_event_id`; the accessor will be removed in a future release.
- `LeadDispatcher::dispatch()` no longer dispatches the Facebook job after a successful Duo send; it just marks the lead `sent`. Facebook delivery is fully decoupled — the `leads:dispatch-facebook` cron picks the lead up on its next tick.
- `LeadPipeline::complete()` now only promotes a lead `draft → pending` — it no longer dispatches `SendLeadToDuoJob`. The lead sits as `pending` until the admin app's `leads:dispatch-pending` cron ships it (typically within a minute). `resend()` likewise just resets the lead to `pending` for the cron to pick up.
- `LeadPipeline::scheduleCompletion()` no longer dispatches a delayed `FinalizeLeadJob`; it only persists any supplied data and leaves the lead as `draft`. Abandoned multi-step drafts are now finalised solely by the admin app's `leads:finalize-drafts` cron once they exceed `draft_timeout_seconds`. The `$delaySeconds` argument is retained for call-site compatibility but is now ignored.
- Facebook eligibility is now driven solely by real Facebook click attribution. A lead fires the `Lead` event (browser pixel and server CAPI) only when it carried an `fbclid` (or the derived `_fbc` cookie) at creation; the decision is computed once in `LeadPipeline::start()` and frozen onto the `fb_eligible` column. Removed the per-form `leads.forms.*.fb_eligible` config flag and the config fallback in `Lead::isFacebookEligible()`, which had to be kept in sync between the frontend and admin apps and silently reported Google/organic/direct leads to Meta on mixed-channel landing pages. **Behaviour change:** forms previously relying on `fb_eligible => true` (e.g. `paid_search`, `ppc_contact`) no longer fire Facebook events for non-Facebook traffic. The `forms` config block remains for per-form `office_id` overrides.

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
