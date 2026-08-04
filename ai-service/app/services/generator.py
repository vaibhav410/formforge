"""Orchestration: prompt → Groq → extract → validate → repair → retry.

The loop:
  1. call the primary model (JSON mode)
  2. deterministically extract/repair JSON from the response
  3. validate against the Pydantic schema contract
  4. on failure, re-prompt the SAME conversation with the validation
     errors (up to MAX_REPAIR_ATTEMPTS total calls)
  5. on provider errors/rate limits, fall back to the free fallback model
Every round-trip is logged as an AttemptLog for Laravel's prompt_logs.
"""

from __future__ import annotations

import asyncio
import re

from pydantic import ValidationError

from ..config import Settings
from ..models.api import AiResult, AttemptLog
from ..models.schema import FormSchemaModel
from . import prompts
from .groq_client import ChatResult, GroqClient, GroqError
from .json_repair import extract_json


class GenerationFailed(Exception):
    def __init__(self, detail: str, attempts: list[AttemptLog]):
        super().__init__(detail)
        self.detail = detail
        self.attempts = attempts


class FormGenerator:
    def __init__(self, settings: Settings, client: GroqClient | None = None):
        self._settings = settings
        self._client = client or GroqClient(settings)

    async def generate(self, prompt: str, locale: str | None) -> AiResult:
        return await self._run(
            system=prompts.generate_system_prompt(),
            user=prompts.generate_user_prompt(prompt, locale),
        )

    async def edit(self, prompt: str, schema: dict) -> AiResult:
        return await self._run(
            system=prompts.edit_system_prompt(),
            user=prompts.edit_user_prompt(prompt, schema),
        )

    async def translate(self, language: str, schema: dict) -> AiResult:
        return await self._run(
            system=prompts.translate_system_prompt(),
            user=prompts.translate_user_prompt(language, schema),
        )

    async def _run(self, system: str, user: str) -> AiResult:
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ]

        attempts: list[AttemptLog] = []
        model = self._settings.groq_model_primary
        total_prompt_tokens = 0
        total_completion_tokens = 0
        last_error = "unknown error"
        waited_for_rate_limit = False

        for attempt_number in range(1, self._settings.max_repair_attempts + 1):
            try:
                result = await self._client.chat(messages, model=model)
            except GroqError as exc:
                attempts.append(AttemptLog(
                    attempt=attempt_number,
                    model=model,
                    outcome="provider_error",
                    latency_ms=0,
                    response_excerpt=str(exc)[:500],
                ))
                last_error = str(exc)
                if not exc.retryable:
                    break
                # Groq free tier enforces small per-minute token budgets.
                # A TPM 429 usually clears within a minute, so wait once
                # for the primary model before dropping to the fallback
                # (whose budget is often too small for edit prompts).
                if self._is_rate_limit(str(exc)) and not waited_for_rate_limit:
                    waited_for_rate_limit = True
                    await asyncio.sleep(self._rate_limit_wait(str(exc)))
                    continue
                if model != self._settings.groq_model_fallback:
                    model = self._settings.groq_model_fallback
                    continue
                break

            total_prompt_tokens += result.prompt_tokens or 0
            total_completion_tokens += result.completion_tokens or 0

            outcome, schema_or_errors = self._parse(result)

            if outcome == "success":
                attempts.append(self._log(attempt_number, result, "success"))
                return AiResult(
                    form_schema=schema_or_errors,
                    model=result.model,
                    total_latency_ms=sum(a.latency_ms for a in attempts),
                    prompt_tokens=total_prompt_tokens,
                    completion_tokens=total_completion_tokens,
                    attempts=attempts,
                )

            attempts.append(self._log(attempt_number, result, outcome))
            errors: list[str] = schema_or_errors
            last_error = "; ".join(errors[:3])

            # Feed the failure back to the model and try again.
            messages.append({"role": "assistant", "content": result.content})
            messages.append({
                "role": "user",
                "content": prompts.repair_user_prompt(errors, result.content),
            })

        raise GenerationFailed(
            f"Could not produce a valid schema after {len(attempts)} attempt(s): {last_error}",
            attempts,
        )

    @staticmethod
    def _is_rate_limit(message: str) -> bool:
        lowered = message.lower()
        return "429" in message or "tokens per minute" in lowered or "rate limit" in lowered

    @staticmethod
    def _rate_limit_wait(message: str) -> float:
        """Honour Groq's 'try again in Xs' hint when present, capped at 60s."""
        match = re.search(r"try again in ([0-9.]+)s", message)
        if match:
            return min(60.0, float(match.group(1)) + 1.0)
        return 25.0

    def _parse(self, result: ChatResult):
        """Returns ("success", schema_dict) or (outcome, [error strings])."""
        try:
            raw = extract_json(result.content)
        except ValueError as exc:
            return "invalid_json", [str(exc)]

        try:
            validated = FormSchemaModel.model_validate(raw)
        except ValidationError as exc:
            return "schema_invalid", [
                f"{'.'.join(str(p) for p in error['loc'])}: {error['msg']}"
                for error in exc.errors()
            ]

        return "success", validated.model_dump(mode="json")

    @staticmethod
    def _log(attempt: int, result: ChatResult, outcome: str) -> AttemptLog:
        return AttemptLog(
            attempt=attempt,
            model=result.model,
            outcome=outcome,  # type: ignore[arg-type]
            latency_ms=result.latency_ms,
            prompt_tokens=result.prompt_tokens,
            completion_tokens=result.completion_tokens,
            response_excerpt=result.content[:500] if outcome != "success" else None,
        )
