# Form Schema Contract (v1)

The single source of truth for every form. Enforced twice, in lockstep:
- PHP: `web/app/Schema/FormSchemaValidator.php` (strict) + `SchemaSanitizer` (normalisation, with a lenient repair mode for AI/import input)
- Python: `ai-service/app/models/schema.py` (Pydantic — LLM output must parse into it before leaving the AI service)

If you change one, change the other.

## Top level

```jsonc
{
  "schema_version": 1,          // must equal 1
  "title": "string, 1–255",
  "description": "string|null",
  "settings": {
    "submit_label": "string (default: Submit)",
    "success_message": "string"
  },
  "sections": [ Section, ... ]  // at least one
}
```

## Section

```jsonc
{
  "id": "sec_<4–16 lowercase alnum>",   // generated if missing
  "title": "string, 1–255",
  "description": "string|null",
  "fields": [ Field, ... ]
}
```

Sections are presentation groups. They exist **only** here — never in a database table.

## Field

```jsonc
{
  "id": "fld_<4–16 lowercase alnum>",   // stable identity for the builder (drag/drop, selection)
  "key": "snake_case, ^[a-z][a-z0-9_]{0,63}$, unique across the whole form",
  "type": "one of the 19 types below",
  "label": "string, 1–255 (HTML stripped)",
  "description": "help text | null",
  "placeholder": "string | null",
  "required": false,
  "default": "scalar | array | null",
  "options": [ {"label": "…", "value": "snake_case, unique"} ],  // REQUIRED (≥1) for dropdown/radio/checkbox; null otherwise
  "validation": {
    "min": "number|date-string|null",   // numeric/rating min, or earliest date
    "max": "number|date-string|null",
    "min_length": "int|null",
    "max_length": "int|null",
    "regex": "PCRE body without delimiters; must compile",
    "mimes": ["pdf", "docx"],           // file only
    "max_size_kb": "int ≤ 51200",       // file only
    "multiple": "bool|null"
  },
  "css_class": "string|null",
  "hidden": false,                       // statically hidden (≠ type "hidden")
  "logic": null | {
    "action": "show" | "hide",
    "match": "all" | "any",
    "conditions": [
      { "field": "<key of ANOTHER field>", "operator": "…", "value": "scalar|null" }
    ]
  },
  "meta": {                              // type-specific extras (whitelisted)
    "rating_max": "2–10 (rating)",
    "rows": "2–20 (textarea)",
    "step": "number (number)",
    "heading_level": "h2|h3 (heading)"
  }
}
```

### Types (19)

`text` `textarea` `number` `email` `phone` `date` `time` `dropdown` `radio` `checkbox` `file` `rating` `heading` `address` `url` `password` `signature` `color` `hidden`

- `heading` is layout-only: collects no answer, never validated.
- `checkbox` answers are arrays; `address` answers are objects (`line1`, `line2`, `city`, `state`, `postal_code`, `country`); `file` answers store `{name, path, size_kb}`.
- `password` answers are encrypted at rest; `signature` answers are PNG data-URIs (≤ ~200 KB).
- `hidden` renders as `<input type=hidden>`; its default may be overridden by a same-named query parameter.

### Logic operators

`equals` `not_equals` `contains` `greater_than` `less_than` `is_empty` `is_not_empty`

Rules: conditions may reference only keys that exist on the form; self-reference is invalid; array answers make `equals`/`contains` mean "is selected". The server (`ConditionEvaluator`) is the authority — a field hidden by logic is not validated and its submitted value is discarded. Alpine mirrors identical semantics client-side for UX only.

## Sanitisation guarantees

Whatever the source (JSON editor, LLM, document import), before validation the sanitizer:
- drops unknown properties at every level, strips HTML from all human-visible strings, clamps lengths
- generates missing `id`s, derives missing `key`s from labels, de-duplicates keys (`email`, `email_2`, …)
- normalises options (`"Red"` → `{"label": "Red", "value": "red"}`), coerces booleans/numbers, verifies regexes compile

Lenient mode (AI/import only) additionally maps hallucinated types (`multiselect` → `checkbox`, `fullname` → `text`, …), invents a placeholder option for optionless choice fields, and drops logic that references unknown fields. Strict mode (builder saves) rejects those instead — the human is there to fix them.

**A schema that fails validation is never persisted, anywhere.**
