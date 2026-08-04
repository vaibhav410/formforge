import hmac

from fastapi import Depends, HTTPException
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from .config import Settings, get_settings

_bearer = HTTPBearer(auto_error=False)


def require_token(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer),
    settings: Settings = Depends(get_settings),
) -> None:
    """Shared-secret auth between Laravel and this service."""
    if not settings.ai_service_token:
        raise HTTPException(status_code=503, detail="AI service token not configured")
    if credentials is None or not hmac.compare_digest(
        credentials.credentials, settings.ai_service_token
    ):
        raise HTTPException(status_code=401, detail="Invalid service token")
