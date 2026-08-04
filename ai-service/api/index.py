"""Vercel serverless entrypoint — re-exports the FastAPI app.

Vercel's Python runtime serves any ASGI `app` found here; all routes
are rewritten to this function (see ../vercel.json).
"""

from app.main import app  # noqa: F401
