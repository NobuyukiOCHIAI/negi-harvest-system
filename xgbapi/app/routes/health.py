from fastapi import APIRouter
from ..services.loader import load_models

router = APIRouter()
_mb_cache = None

@router.get("/healthz")
def healthz():
    return {"status": "ok"}

@router.get("/readyz")
def readyz():
    global _mb_cache
    if _mb_cache is None:
        _mb_cache = load_models()
    if _mb_cache.ok:
        return {"status": "ready", "model_path": _mb_cache.model_path}
    return {"status": "not_ready", "reason": _mb_cache.reason}

@router.get("/version")
def version():
    import os
    return {
        "api": "1.0.0",
        "model": os.getenv("MODEL_NAME", "yield_days_xgb"),
        "model_version": os.getenv("MODEL_VERSION", "2025-08-10_001")
    }
