from fastapi import Header, HTTPException, status
import os

API_KEY_ENV = os.getenv("API_KEY", "change-me-please")

async def api_key_guard(x_api_key: str | None = Header(default=None)):
    if not x_api_key or x_api_key != API_KEY_ENV:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid API key")
