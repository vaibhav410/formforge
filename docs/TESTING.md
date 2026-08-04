# Testing

**96 automated tests** across both services — 74 Pest (254 assertions) + 22 pytest — all green, run on every push by GitHub Actions.

```bash
cd web && php artisan test            # SQLite in-memory + sync queue: no services needed
cd ai-service && python -m pytest     # scripted fake Groq client: no API key needed
```

## Laravel suite (Pest)

| File | Covers |
|---|---|
| `tests/Unit/SchemaEngineTest.php` | Sanitizer (HTML stripping, key dedupe/derivation, hallucinated-type mapping, bare-option repair, broken-logic dropping, numbered/non-Latin label keys); validator (duplicate keys, unknown types, empty options, self/unknown logic refs, non-compiling regex — all with JSON paths); every condition operator incl. hide-inversion and checkbox arrays; rule compiler visibility + option whitelists |
| `tests/Feature/FormLifecycleTest.php` | v1 draft → publish → next-draft-on-edit → rollback-as-new-version; invalid schema is never persisted |
| `tests/Feature/PublicFormTest.php` | Draft 404, view dedup, schema-derived server validation, conditional required enforced *and* discarded correctly, honeypot + minimum-fill-time silently swallow bots |
| `tests/Feature/BuilderTest.php` | Owner-only mount, autosave, duplicate/move/remove key-uniqueness, JSON editor error paths never touch canvas or DB, publish blocked while broken, undo snapshots |
| `tests/Feature/AiFlowTest.php` | Against a faked AI service: generate persists form + prompt logs, edit drafts, 422 failure telemetry, hallucinated types repaired leniently |
| `tests/Feature/ImportParserTest.php` | Word/Excel parsers pinned against the committed sample files; garbage-file rejection (no silent CSV fallback) |
| `tests/Feature/ImportWizardFlowTest.php` | Full wizard end-to-end (upload → parse → map → commit → redirect); numbered-question keys commit cleanly |
| `tests/Feature/SubmissionsAndExportTest.php` | Search, authorization, schema-ordered CSV with BOM, owner-only download |
| `tests/Feature/Auth/*` | Breeze auth flows (login, registration, password reset, email verification) |

## AI service suite (pytest)

| File | Covers |
|---|---|
| `tests/test_json_repair.py` | Fence/prose extraction, trailing commas, stack-based closing of truncated output, braces-in-strings, hard failure |
| `tests/test_schema_models.py` | Contract mirror: type whitelist, duplicate keys, optionless choice fields, bad key format, unknown/self logic refs, invalid regex, hallucinated-property tolerance |
| `tests/test_endpoints.py` | Auth (401), happy path, **repair loop recovers from invalid schema**, give-up after max attempts with full telemetry, edit round-trip — via a scripted fake Groq client |

## Bugs the suite caught (kept as regression tests)

1. `guessType` matched "select" before "multi" → multiselect became a dropdown.
2. The JSON-editor error path let an unsanitized schema reach the canvas renderer.
3. PhpSpreadsheet silently "parsed" garbage via its CSV fallback reader.
4. Numbered questions ("2. Email") produced digit-leading keys the contract rejects.

## Beyond unit/feature tests

- **Real-browser verification** (Playwright driving Edge) was used throughout development and against the **production** deployment: login, dashboard, builder click-to-add, import wizard end-to-end, and AI generation through the live Render → Vercel → Groq chain. These scripts caught three client-side bugs no server-side test could see (a Livewire `$wire.commit` name collision, a zombie poller swallowing clicks, and dead asset URLs from a stale Vite `hot` file).
- **Live LLM tests** during development validated real Groq behaviour, including free-tier TPM rate-limit recovery.

## CI

`.github/workflows/ci.yml` runs both suites (PHP 8.3 + built assets; Python 3.12) on every push/PR.
