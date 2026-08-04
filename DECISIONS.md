# DECISIONS

Assumptions made where the brief was silent, the trade-offs accepted, and what I'd do next.

## Assumptions

1. **"Sections" live inside the schema, not in a table.** The brief lists a `Sections` table *and* demands the JSON schema be the single source of truth with no duplicated field definitions. Those conflict; SSOT wins. Sections are schema nodes — there is no query that needs them relationally, and a table copy would drift.
2. **One owner per form.** Multi-tenancy is user-scoped (`user_id` + policies). Teams/orgs were out of scope; the tenant boundary is a policy class, so swapping `user_id` for `team_id` later is contained.
3. **Public forms are single-page.** "Steps" in the brief read as grouping; sections render as titled groups. A wizard is a renderer concern only — the schema already has the structure.
4. **AI edits apply to the draft, not the live form.** "Add an emergency contact" writes a new draft version (`source=ai`); publishing stays an explicit human action. Feels safer than letting an LLM mutate a live public form.
5. **Laravel 11 despite EOL advisories.** The brief pins Laravel 10/11; 11 passed its security-EOL (Mar 2026) so Composer 2.10 blocks it by default. I disabled the advisory block for this project (`policy.advisories.block false`) and noted it here rather than silently upgrading to 12 against the brief.
6. **Groq only.** The requirement was any free provider; the product owner asked for Groq exclusively. The AI service has exactly one client class, so adding a provider is one file — but none is included, deliberately.

## Key design decisions & trade-offs

**Immutable version snapshots with a published pointer.**
`form_versions` rows are never edited once published; `forms.published_version_id` selects the live one; edits open the next draft; rollback *publishes a copy* of an old schema as a new version. Trade-off: schema JSON is duplicated per version (storage) in exchange for exact reproducibility — every submission pins the `form_version_id` it validated against, so old answers always render correctly after the form changes.

**EAV answers (`submission_answers`) instead of a JSON blob per submission.**
Costs one row per answer and an insert loop; buys indexed per-field queries (`form_id, field_key`), searchable submissions, streaming CSV export without JSON scans, and per-field analytics. A JSON copy on the submission row was considered and rejected as duplicated state.

**One save path for every writer.**
Builder autosave, the JSON editor, AI results and import commits all pass through `FormService` → sanitize → validate → persist. Lenient mode (AI/import) repairs what it can *before* strict validation; nothing bypasses the validator. This is the load-bearing guarantee: a broken schema cannot reach the database, so every consumer downstream may trust what it reads.

**Server-side conditional logic as the authority.**
Alpine evaluates the same logic client-side purely for UX. The compiler re-evaluates visibility *from the submitted values themselves* and drops hidden fields' answers. Trade-off: logic must stay expressible in both languages, so the operator set is deliberately small (7 operators).

**Raw event stream + nightly rollups for analytics.**
`form_events` is append-only and cheap to write on the hot path (beacons). Dashboards read raw events today (instant at demo scale) and the nightly `AggregateAnalyticsJob` produces `form_analytics_daily`; at real scale the dashboard switches to rollups — the swap point is one service class. 90-day event retention after aggregation.

**Free-tier LLM engineering.**
Groq's TPM limits shaped real code: compact JSON in edit prompts, a wait-for-the-hint retry before model fallback, deterministic JSON repair before spending tokens, and typed failure telemetry. The repair loop feeds validator errors back to the *same conversation* — materially better than blind retries.

**Passwords and PII.**
Password-type answers are encrypted at rest and masked in CSV exports; the owner's UI decrypts. IPs are stored as salted daily hashes — enough for throttling/dedup, useless for tracking. Trade-off: no raw IP forensics.

**Excel garbage handling.**
PhpSpreadsheet silently "reads" any text file via its CSV fallback; the parser pins an explicit Xlsx reader and rejects non-spreadsheets with a clear stored error. Found by a test, kept as a test.

## Part D choices (and why these)

1. **Versioning + rollback with per-version diffs** — the natural payoff of the snapshot data model; the diff view (added/removed/changed keys per version) makes AI edits auditable, which matters when an LLM writes to your form.
2. **Completion & drop-off analytics** — the funnel (view → start → field_focus → abandon/submit) uses `sendBeacon` so abandonment is actually captured; the drop-off chart names the exact field where people give up. This is the feature a real form-builder customer pays for.
3. **Conditional logic engine** — implemented at the schema level so *one* definition drives the builder editor, the client mirror and the server authority; the "hidden required field is neither enforced nor stored" behaviour is tested.
4. (Supporting cast, built because A–C deserved them: autosave + undo/redo, rate limiting + honeypot + min-fill-time, Redis-cached published schemas, QR share, 93 tests, Docker + CI.)

## With two more weeks

1. **Webhooks + public submissions API** (signed payloads, per-form tokens) — the top integration ask for any form product.
2. **Multi-step public forms** with per-step validation and resume-by-link, straight from the existing sections.
3. **Parallel locales** — store `labels.{locale}` maps instead of in-place translation; the AI translate endpoint already returns the right shape.
4. **Concurrent-edit safety** — draft versions carry an edit token; second editor gets a read-only banner (Livewire polling makes this cheap).
5. **S3 uploads + signed download URLs**, and moving CSV exports to streamed S3 multipart.
6. **Template library** seeded from the best AI generations, with "start from similar form" suggestions using pgvector/embeddings of schema titles+keys.
