"""Request/response DTOs for the REST API consumed by Laravel."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field


class GenerateRequest(BaseModel):
    prompt: str = Field(min_length=3, max_length=4000)
    locale: str | None = Field(default=None, max_length=10)


class EditRequest(BaseModel):
    prompt: str = Field(min_length=3, max_length=4000)
    form_schema: dict[str, Any] = Field(alias="schema")

    model_config = {"populate_by_name": True}


class TranslateRequest(BaseModel):
    target_language: str = Field(min_length=2, max_length=50)
    form_schema: dict[str, Any] = Field(alias="schema")

    model_config = {"populate_by_name": True}


class AttemptLog(BaseModel):
    """One LLM round-trip; Laravel persists these as prompt_logs rows."""

    attempt: int
    model: str
    outcome: Literal["success", "invalid_json", "schema_invalid", "provider_error"]
    latency_ms: int
    prompt_tokens: int | None = None
    completion_tokens: int | None = None
    response_excerpt: str | None = None


class AiResult(BaseModel):
    form_schema: dict[str, Any] = Field(serialization_alias="schema")
    model: str
    total_latency_ms: int
    prompt_tokens: int
    completion_tokens: int
    attempts: list[AttemptLog]


class ErrorResponse(BaseModel):
    detail: str
    attempts: list[AttemptLog] = []
