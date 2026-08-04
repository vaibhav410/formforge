from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Service configuration, loaded from ai-service/.env."""

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    groq_api_key: str = ""
    groq_model_primary: str = "llama-3.3-70b-versatile"
    groq_model_fallback: str = "llama-3.1-8b-instant"
    groq_base_url: str = "https://api.groq.com/openai/v1"

    ai_service_token: str = ""

    max_repair_attempts: int = 3
    request_timeout_seconds: int = 90
    log_level: str = "INFO"


@lru_cache
def get_settings() -> Settings:
    return Settings()
