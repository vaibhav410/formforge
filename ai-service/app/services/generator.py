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
                if exc.retryable and model != self._settings.groq_model_fallback:
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
