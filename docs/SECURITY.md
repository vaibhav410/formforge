# Security

The governing rule, straight from the brief: **never trust the browser.** Every guarantee below is enforced server-side; client-side mirrors exist only for UX.

## Authentication & authorization

- Session authentication via Laravel Breeze (bcrypt hashes, remember tokens, email-verification scaffolding).
- **Owner-only access** to every form-scoped resource through `FormPolicy` (view/update/delete/publish/viewSubmissions) — enforced in every Livewire `mount()` and controller. Cross-tenant access attempts return 403 (covered by tests).
- Imports and exports are additionally scoped to `auth()->id()` at the query level, so even a leaked UUID reveals nothing.
- The Horizon dashboard is gated to an explicit account allowlist.

## Public form hardening

| Threat | Defence |
|---|---|
| Forged/hand-crafted submissions | Validation rules are **compiled from the stored schema on every request** (`ValidationRuleCompiler`); unknown fields are ignored, option values are whitelisted via `Rule::in` |
| Tampering with conditional logic | `ConditionEvaluator` recomputes visibility server-side **from the submitted values**; answers to logic-hidden fields are discarded and hidden-required fields are not enforced (tested both ways) |
| Bots | Honeypot field (visually hidden) **and** minimum-fill-time check via an encrypted render token — both return a fake success page and store nothing, giving bots no signal |
| Flooding | Per-form+IP submission throttle, per-IP beacon throttle, per-user AI quota (Laravel RateLimiter) |
| Draft leakage | Public routes 404 unless the form is published; preview requires ownership |

## Injection & XSS

- All persistence through Eloquent/query-builder parameter binding — no raw user SQL anywhere.
- `SchemaSanitizer` strips HTML from every human-readable schema string (labels, descriptions, placeholders, options) *at write time* — LLM output and imported documents get no HTML into stored schemas; Blade escaping applies at render time as the second layer. The JSON editor cannot smuggle unknown properties past the whitelist.
- User-supplied regex validation patterns are compile-checked before storage and never `eval`ed.
- LIKE search terms are escaped (`%`/`_`) before binding.

## Data protection

- **Password-type answers are encrypted at rest** (Laravel Crypt); decrypted only in the owner's submissions UI and masked as `[encrypted]` in CSV exports.
- **IP addresses are never stored raw** — a salted daily SHA-256 (`hash(ip|date)`) suffices for throttling and dedup while being useless for tracking.
- File uploads: extension + size validated from the schema, stored on the **private** local disk (`storage/app/submissions/...`), never web-accessible; export downloads stream through an authorised controller.
- Signature answers are size-capped data-URIs validated against a strict PNG pattern.

## Secrets & transport

- No secret is committed: `.env` files are gitignored, `.env.example` documents every variable with placeholders, and the git **history** was audited (no key, token, or dump in any commit).
- The Groq API key exists **only** in the AI service's environment — the Laravel app cannot leak what it never holds.
- Laravel ↔ AI service auth is a shared bearer token compared in constant time (`hmac.compare_digest`); the hosted service also relies on this because platform-level protection must stay off for server-to-server calls.
- CSRF on all state-changing web routes (Laravel middleware + Livewire); trusted-proxy configuration ensures correct HTTPS detection behind PaaS load balancers.

## Dependency posture

- Composer 2.10 security-advisory audit is enabled; the sole accepted exception is Laravel 11 itself, which the brief mandates and which passed its security-EOL window — documented in DECISIONS.md rather than silently bypassed.
- Python dependencies are minimal (FastAPI/pydantic/httpx) and pinned by floor version.

## Known gaps (honest list)

- No 2FA / password-strength policy beyond Breeze defaults (out of scope).
- Signed URLs are not used for export downloads (authorised controller instead) — equivalent control, different mechanism.
- The public submissions API + webhook signing described in DECISIONS.md ("next two weeks") does not exist yet, so there is no third-party API surface to harden.
