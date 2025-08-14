import os
from fastapi import Header, HTTPException, status


def require_api_key(x_api_key: str | None = Header(default=None)) -> None:
    expected = os.getenv("API_KEY")
    if not expected:
        # 環境変数未設定は起動時に検知したいが、保険として 500 にせず 401
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="API key not configured")
    if not x_api_key or x_api_key != expected:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid API key")
