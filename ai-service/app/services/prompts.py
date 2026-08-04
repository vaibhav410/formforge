"""Prompt engineering for form generation, editing and translation.

The output contract is stated three ways — prose rules, the type list,
and a worked example — because smaller models follow examples far more
reliably than rules. Documented for reviewers in the repo README.
"""

from __future__ import annotations

import json
from typing import Any

SCHEMA_CONTRACT = """\
You output form definitions as a single JSON object with EXACTLY this structure:

{
  "schema_version": 1,
  "title": "<form title>",
  "description": "<one-line intro or null>",
  "settings": {"submit_label": "Submit", "success_message": "<thank-you text>"},
  "sections": [
    {
      "title": "<section title>",
      "description": null,
      "fields": [ <field objects> ]
    }
  ]
}

Every field object:
{
  "key": "<snake_case unique across the whole form, starts with a letter>",
  "type": "<one of the allowed types>",
  "label": "<human label>",
  "description": "<help text or null>",
  "placeholder": "<example value or null>",
  "required": true|false,
  "default": null,
  "options": [{"label": "...", "value": "<snake_case>"}],   // ONLY for dropdown/radio/checkbox, else null
  "validation": {"min": null, "max": null, "min_length": null, "max_length": null,
                 "regex": null, "mimes": null, "max_size_kb": null, "multiple": null},
  "css_class": null,
  "hidden": false,
  "logic": null,
  "meta": {}
}

Allowed types (use NOTHING else):
text, textarea, number, email, phone, date, time, dropdown, radio, checkbox,
file, rating, heading, address, url, password, signature, color, hidden

Conditional visibility, when genuinely useful:
"logic": {"action": "show", "match": "all",
          "conditions": [{"field": "<other_field_key>", "operator": "equals", "value": "..."}]}
Operators: equals, not_equals, contains, greater_than, less_than, is_empty, is_not_empty.
A field's logic may only reference keys of OTHER fields that exist in this form.

Hard rules:
- Respond with the JSON object ONLY. No markdown fences, no commentary.
- Field keys must be unique across all sections.
- dropdown/radio/checkbox MUST have 2+ options with snake_case values.
- file fields: set validation.mimes (e.g. ["pdf","docx"]) and validation.max_size_kb (e.g. 5120).
- rating fields: set meta.rating_max (2–10, usually 5).
- Use sensible validations: email type for emails, min/max for numbers,
  min_length/max_length for free text, date min/max as "YYYY-MM-DD" strings.
- Group related fields into 2–4 titled sections for anything non-trivial.
- Mark a field required only when a real form would require it.
"""

GENERATE_EXAMPLE = {
    "schema_version": 1,
    "title": "Gym Membership Application",
    "description": "Join our fitness community.",
    "settings": {
        "submit_label": "Apply",
        "success_message": "Thanks — we will contact you within 2 days.",
    },
    "sections": [
        {
            "title": "Personal details",
            "description": None,
            "fields": [
                {
                    "key": "full_name", "type": "text", "label": "Full name",
                    "description": None, "placeholder": "Jane Doe", "required": True,
                    "default": None, "options": None,
                    "validation": {"min": None, "max": None, "min_length": 2, "max_length": 100,
                                   "regex": None, "mimes": None, "max_size_kb": None, "multiple": None},
                    "css_class": None, "hidden": False, "logic": None, "meta": {},
                },
                {
                    "key": "membership_type", "type": "radio", "label": "Membership type",
                    "description": None, "placeholder": None, "required": True,
                    "default": None,
                    "options": [
                        {"label": "Monthly", "value": "monthly"},
                        {"label": "Annual", "value": "annual"},
                    ],
                    "validation": {"min": None, "max": None, "min_length": None, "max_length": None,
                                   "regex": None, "mimes": None, "max_size_kb": None, "multiple": None},
                    "css_class": None, "hidden": False, "logic": None, "meta": {},
                },
                {
                    "key": "referral_code", "type": "text", "label": "Referral code",
                    "description": "Only shown for annual members.", "placeholder": None,
                    "required": False, "default": None, "options": None,
                    "validation": {"min": None, "max": None, "min_length": None, "max_length": 20,
                                   "regex": None, "mimes": None, "max_size_kb": None, "multiple": None},
                    "css_class": None, "hidden": False,
                    "logic": {"action": "show", "match": "all",
                              "conditions": [{"field": "membership_type", "operator": "equals",
                                              "value": "annual"}]},
                    "meta": {},
                },
            ],
        }
    ],
}


def generate_system_prompt() -> str:
    return (
        "You are the form-schema engine of FormForge, a professional form builder. "
        "You turn a plain-language request into a complete, production-quality form.\n\n"
        + SCHEMA_CONTRACT
        + "\nExample output for the request \"gym membership form with a referral code for annual members\":\n"
        + json.dumps(GENERATE_EXAMPLE, ensure_ascii=False)
    )


def generate_user_prompt(prompt: str, locale: str | None) -> str:
    suffix = f"\nWrite all labels and messages in locale: {locale}." if locale else ""
    return f"Create a form for this request:\n{prompt}{suffix}"


def edit_system_prompt() -> str:
    return (
        "You are the form-schema engine of FormForge. You receive an existing form "
        "schema and an instruction, and return the COMPLETE updated schema.\n\n"
        + SCHEMA_CONTRACT
        + "\nEditing rules:\n"
        "- Return the ENTIRE schema after the change, not a fragment or diff.\n"
        "- Preserve every existing field's id, key, and settings unless the "
        "instruction explicitly asks to change or remove it.\n"
        "- New fields follow all schema rules above.\n"
        "- If the instruction is unrelated to editing this form, return the "
        "schema unchanged."
    )


def edit_user_prompt(prompt: str, schema: dict[str, Any]) -> str:
    return (
        "Current form schema:\n"
        + json.dumps(schema, ensure_ascii=False, separators=(",", ":"))
        + f"\n\nInstruction: {prompt}"
    )


def translate_system_prompt() -> str:
    return (
        "You are the form-schema engine of FormForge. You translate the human-visible "
        "text of a form schema.\n\n"
        "Translate ONLY: title, description, settings.submit_label, "
        "settings.success_message, section titles/descriptions, field labels, "
        "field descriptions, placeholders, and option LABELS.\n"
        "NEVER change: schema_version, ids, keys, types, option VALUES, validation, "
        "logic, meta, css_class, or the structure itself.\n"
        "Respond with the complete translated JSON object only — no commentary."
    )


def translate_user_prompt(language: str, schema: dict[str, Any]) -> str:
    return (
        f"Translate this form into {language}:\n"
        + json.dumps(schema, ensure_ascii=False, separators=(",", ":"))
    )


def repair_user_prompt(errors: list[str], raw: str) -> str:
    return (
        "Your previous response failed validation with these errors:\n- "
        + "\n- ".join(errors[:10])
        + "\n\nYour previous response was:\n"
        + raw[:6000]
        + "\n\nReturn the corrected COMPLETE JSON object only. Fix every listed "
        "error while keeping everything that was already valid."
    )
