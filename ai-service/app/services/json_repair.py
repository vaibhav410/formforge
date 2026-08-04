"""Deterministic JSON extraction and repair for LLM output.

Cheap fixes happen here without burning tokens; only when these fail
does the generator loop go back to the model with the error messages.
"""

from __future__ import annotations

import json
import re
from typing import Any


def extract_json(text: str) -> dict[str, Any]:
    """Pull the first JSON object out of an LLM response.

    Handles: markdown fences, leading prose ("Here is your form:"),
    trailing commentary, trailing commas, and unbalanced braces from
    truncated output.

    Raises ValueError when nothing parseable is found.
    """
    candidates: list[str] = []

    fence = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.DOTALL)
    if fence:
        candidates.append(fence.group(1))

    start = text.find("{")
    if start != -1:
        candidates.append(_balanced_slice(text, start))
        candidates.append(text[start:])

    for candidate in candidates:
        for variant in (candidate, _strip_trailing_commas(candidate), _close_braces(candidate)):
            try:
                parsed = json.loads(variant)
                if isinstance(parsed, dict):
                    return parsed
            except json.JSONDecodeError:
                continue

    raise ValueError("no JSON object found in model output")


def _balanced_slice(text: str, start: int) -> str:
    """Slice from the first '{' to its balanced closing brace."""
    depth = 0
    in_string = False
    escaped = False
    for index in range(start, len(text)):
        char = text[index]
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
            continue
        if char == '"':
            in_string = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return text[start : index + 1]
    return text[start:]


def _strip_trailing_commas(text: str) -> str:
    return re.sub(r",\s*([}\]])", r"\1", text)


def _close_braces(text: str) -> str:
    """Best-effort completion of truncated output.

    Walks the text tracking the open-container stack (ignoring braces
    inside strings) and closes containers in reverse order, so
    '{"a": [{"b": [' becomes '{"a": [{"b": []}]}'.
    """
    text = _strip_trailing_commas(text.rstrip())
    if text.endswith(","):
        text = text[:-1]

    stack: list[str] = []
    in_string = False
    escaped = False
    for char in text:
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
            continue
        if char == '"':
            in_string = True
        elif char in "{[":
            stack.append(char)
        elif char in "}]":
            if stack:
                stack.pop()

    if in_string:
        text += '"'

    closers = {"{": "}", "[": "]"}
    return text + "".join(closers[open_char] for open_char in reversed(stack))
