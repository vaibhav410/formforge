"""Thin async client for Groq's OpenAI-compatible chat completions API.

Groq is the only provider — its free tier covers this whole project.
The fallback model is also a free Groq model, used on rate limits and
provider errors, never a paid service.
"""

from __future__ import annotations

import time
from dataclasses import dataclass

import httpx

from ..config import Settings


class GroqError(RuntimeError):
    def __init__(self, message: str, retryable: bool):
        super().__init__(message)
        self.retryable = retryable


@dataclass
class ChatResult:
    content: str
    model: str
    prompt_tokens: int | None
    completion_tokens: int | None
    latency_ms: int


class GroqClient:
    def __init__(self, settings: Settings):
        self._settings = settings

    async def chat(
        self,
        messages: list[dict[str, str]],
        model: str,
        json_mode: bool = True,
    ) -> ChatResult:
        payload: dict = {
            "model": model,
            "messages": messages,
            "temperature": 0.3,
            "max_tokens": 8000,
        }
        if json_mode:
            payload["response_format"] = {"type": "json_object"}

        started = time.perf_counter()
        async with httpx.AsyncClient(timeout=self._settings.request_timeout_seconds) as client:
            try:
                response = await client.post(
                    f"{self._settings.groq_base_url}/chat/completions",
                    headers={"Authorization": f"Bearer {self._settings.groq_api_key}"},
                    json=payload,
                )
            except httpx.HTTPError as exc:
                raise GroqError(f"Groq unreachable: {exc}", retryable=True)

        latency_ms = int((time.perf_counter() - started) * 1000)

        if response.status_code in (429, 500, 502, 503):
            raise GroqError(
                f"Groq {response.status_code}: {response.text[:300]}", retryable=True
            )
        if response.status_code != 200:
            raise GroqError(
                f"Groq {response.status_code}: {response.text[:300]}", retryable=False
            )

        data = response.json()
        usage = data.get("usage") or {}

        return ChatResult(
            content=data["choices"][0]["message"]["content"],
            model=data.get("model", model),
            prompt_tokens=usage.get("prompt_tokens"),
            completion_tokens=usage.get("completion_tokens"),
            latency_ms=latency_ms,
        )
