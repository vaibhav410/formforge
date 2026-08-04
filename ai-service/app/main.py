"""FormForge AI service - FastAPI app.

Laravel is the only intended client. Auth is a shared bearer token;
all endpoints are synchronous request/response (Laravel calls them
from queued jobs, so long LLM latency never blocks a web request).
"""

from __future__ import annotations

import logging

from fastapi import Depends, FastAPI
from fastapi.responses import JSONResponse

from .auth import require_token
from .config import get_settings
from .models.api import (
    AiResult,
    EditRequest,
    ErrorResponse,
    GenerateRequest,
    TranslateRequest,
)
from .services.generator import FormGenerator, GenerationFailed

logging.basicConfig(level=get_settings().log_level)
logger = logging.getLogger("formforge-ai")

app = FastAPI(
    title="FormForge AI Service",
    version="1.0.0",
    description=(
        "LLM layer for the FormForge form builder. Converts natural-language "
        "prompts into validated form schemas, edits existing schemas, and "
        "translates them. Groq-only (free tier)."
    ),
)


def get_generator() -> FormGenerator:
    return FormGenerator(get_settings())


@app.exception_handler(GenerationFailed)
async def generation_failed_handler(_, exc: GenerationFailed) -> JSONResponse:
    logger.warning("generation failed: %s", exc.detail)
    return JSONResponse(
        status_code=422,
        content=ErrorResponse(
            detail=exc.detail,
            attempts=exc.attempts,
        ).model_dump(),
    )


@app.get("/health")
async def health() -> dict:
    settings = get_settings()
    return {
        "status": "ok",
        "provider": "groq",
        "model": settings.groq_model_primary,
        "fallback": settings.groq_model_fallback,
        "key_configured": bool(settings.groq_api_key),
    }


@app.post(
    "/v1/forms/generate",
    response_model=AiResult,
    response_model_by_alias=True,
    dependencies=[Depends(require_token)],
    responses={422: {"model": ErrorResponse}},
)
async def generate_form(
    request: GenerateRequest,
    generator: FormGenerator = Depends(get_generator),
) -> AiResult:
    logger.info("generate: %r", request.prompt[:120])
    return await generator.generate(request.prompt, request.locale)


@app.post(
    "/v1/forms/edit",
    response_model=AiResult,
    response_model_by_alias=True,
    dependencies=[Depends(require_token)],
    responses={422: {"model": ErrorResponse}},
)
async def edit_form(
    request: EditRequest,
    generator: FormGenerator = Depends(get_generator),
) -> AiResult:
    logger.info("edit: %r", request.prompt[:120])
    return await generator.edit(request.prompt, request.form_schema)


@app.post(
    "/v1/forms/translate",
    response_model=AiResult,
    response_model_by_alias=True,
    dependencies=[Depends(require_token)],
    responses={422: {"model": ErrorResponse}},
)
async def translate_form(
    request: TranslateRequest,
    generator: FormGenerator = Depends(get_generator),
) -> AiResult:
    logger.info("translate to: %s", request.target_language)
    return await generator.translate(request.target_language, request.form_schema)
