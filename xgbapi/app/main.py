from __future__ import annotations

import json
import logging
import logging.config
import os
from datetime import datetime
from pathlib import Path
from typing import List

from dotenv import load_dotenv
from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import JSONResponse

from app.deps.auth import require_api_key
from app.routes.predict import router as predict_router

load_dotenv()

API_HOST = os.getenv("API_HOST", "0.0.0.0")
API_PORT = int(os.getenv("API_PORT", "8080"))
APP_VERSION = os.getenv("APP_VERSION", datetime.utcnow().strftime("%Y.%m.%d"))
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()
MODEL_PATH_DAYS = os.getenv("MODEL_PATH_DAYS")
MODEL_PATH_YIELD = os.getenv("MODEL_PATH_YIELD")
PREPROC_PATH = os.getenv("PREPROC_PATH")
FEATURE_META_PATH = os.getenv("FEATURE_META_PATH")

logger = logging.getLogger("xgbapi")


def _split_env(name: str, default: str) -> List[str]:
    return [v.strip() for v in os.getenv(name, default).split(",") if v.strip()]


class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        rid = request.headers.get("X-Request-ID") or datetime.utcnow().strftime("%Y%m%d%H%M%S%f")
        request.state.request_id = rid
        response = await call_next(request)
        response.headers["X-Request-ID"] = rid
        return response


app = FastAPI(title="XGBoost Predict API", version=APP_VERSION)

# CORS configuration from environment
allow_origins = _split_env("CORS_ALLOW_ORIGINS", "*")
allow_methods = _split_env("CORS_ALLOW_METHODS", "GET,POST,OPTIONS")
allow_headers = _split_env("CORS_ALLOW_HEADERS", "X-Api-Key,Content-Type,Accept")
app.add_middleware(
    CORSMiddleware,
    allow_origins=allow_origins,
    allow_methods=allow_methods,
    allow_headers=allow_headers,
)
app.add_middleware(RequestIdMiddleware)

# API key protection
app.include_router(predict_router, prefix="/api", dependencies=[Depends(require_api_key)])


@app.on_event("startup")
async def on_startup() -> None:
    cfg_path = Path(__file__).resolve().parent.parent / "config" / "logging.json"
    if cfg_path.exists():
        with open(cfg_path, "r", encoding="utf-8") as f:
            logging.config.dictConfig(json.load(f))
    logger.info("[CONFIG] API_HOST           = %s", API_HOST)
    logger.info("[CONFIG] API_PORT           = %s", API_PORT)
    logger.info("[CONFIG] MODEL_PATH_DAYS    = %s", MODEL_PATH_DAYS)
    logger.info("[CONFIG] MODEL_PATH_YIELD   = %s", MODEL_PATH_YIELD)
    logger.info("[CONFIG] PREPROC_PATH       = %s", PREPROC_PATH)
    logger.info("[CONFIG] FEATURE_META_PATH  = %s", FEATURE_META_PATH)
    logger.info("[CONFIG] LOG_LEVEL          = %s", LOG_LEVEL)
    logger.info("[CONFIG] APP_VERSION        = %s", APP_VERSION)


@app.get("/healthz")
async def healthz() -> dict:
    return {"ok": True}


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    rid = getattr(request.state, "request_id", datetime.utcnow().strftime("%Y%m%d%H%M%S%f"))
    detail = exc.detail
    if isinstance(detail, dict):
        code = detail.get("code", exc.status_code)
        message = detail.get("message", "")
    else:
        code = exc.status_code
        message = str(detail)
    return JSONResponse(
        status_code=exc.status_code,
        content={"ok": False, "error": {"code": code, "message": message}, "request_id": rid},
    )


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    rid = getattr(request.state, "request_id", datetime.utcnow().strftime("%Y%m%d%H%M%S%f"))
    return JSONResponse(
        status_code=422,
        content={
            "ok": False,
            "error": {"code": 422, "message": exc.errors()},
            "request_id": rid,
        },
    )


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("app.main:app", host=API_HOST, port=API_PORT)
