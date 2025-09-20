from __future__ import annotations

import json
import logging
import os
from datetime import datetime
from types import SimpleNamespace
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, HTTPException, Request

from app.services import feature_shim

try:  # Required to load serialized models
    import joblib
except Exception as exc:  # pragma: no cover - import guard
    raise RuntimeError("joblib is required to load model artifacts") from exc

try:  # Required for array math / rounding
    import numpy as np
except Exception as exc:  # pragma: no cover - import guard
    raise RuntimeError("numpy is required for prediction endpoints") from exc

try:  # Optional: only needed when preprocessing pipeline exists
    import pandas as pd  # type: ignore
except Exception:  # pragma: no cover - optional dependency
    pd = None

router = APIRouter()
log = logging.getLogger("xgbapi.predict")

MODEL_PATH_DAYS = os.getenv("MODEL_PATH_DAYS")
MODEL_PATH_YIELD = os.getenv("MODEL_PATH_YIELD")
PREPROC_PATH = os.getenv("PREPROC_PATH")
FEATURE_META_PATH = os.getenv("FEATURE_META_PATH")

_ARTIFACTS: Optional[SimpleNamespace] = None
_SIG: Optional[str] = None

REQUIRED_TEMP_KEYS = [
    "気温_平均",
    "気温_最大",
    "気温_最小",
    "気温_std",
    "気温振れ幅_平均",
    "気温振れ幅_std",
]
REQUIRED_OTHER_KEYS = ["営業調整日数"]


def _mtime(path: Optional[str]) -> str:
    if not path:
        return "-"
    try:
        return str(os.path.getmtime(path))
    except Exception:
        return "NA"


def _signature() -> str:
    parts = [
        f"DAYS:{MODEL_PATH_DAYS or '-'}:{_mtime(MODEL_PATH_DAYS)}",
        f"YIELD:{MODEL_PATH_YIELD or '-'}:{_mtime(MODEL_PATH_YIELD)}",
        f"PREP:{PREPROC_PATH or '-'}:{_mtime(PREPROC_PATH)}",
        f"META:{FEATURE_META_PATH or '-'}:{_mtime(FEATURE_META_PATH)}",
    ]
    return "|".join(parts)


def _safe_list(obj: Any) -> Optional[List[str]]:
    if obj is None:
        return None
    try:
        return [str(v) for v in list(obj)]
    except Exception:
        return None


def _load_json(path: str) -> Any:
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def _load_feature_order_from_meta() -> Optional[List[str]]:
    if not FEATURE_META_PATH or not os.path.isfile(FEATURE_META_PATH):
        return None
    try:
        meta = _load_json(FEATURE_META_PATH)
        for key in ("feature_order", "feature_names", "columns", "feature_cols"):
            arr = _safe_list(meta.get(key))
            if arr and all(isinstance(s, str) for s in arr):
                return arr
    except Exception:
        log.exception("Failed to load FEATURE_META_PATH=%s", FEATURE_META_PATH)
    return None


def _infer_feature_order_from_model(model: Any) -> Optional[List[str]]:
    if model is None:
        return None
    arr = _safe_list(getattr(model, "feature_names_in_", None))
    if arr and all(isinstance(s, str) for s in arr):
        return arr
    if hasattr(model, "get_booster"):
        try:
            booster = model.get_booster()
            arr = _safe_list(getattr(booster, "feature_names", None))
            if arr and all(isinstance(s, str) for s in arr):
                return arr
        except Exception:
            return None
    return None


def get_model_and_artifacts() -> SimpleNamespace:
    global _ARTIFACTS, _SIG

    sig = _signature()
    if _ARTIFACTS is not None and sig == _SIG:
        return _ARTIFACTS

    if not MODEL_PATH_DAYS or not MODEL_PATH_YIELD:
        raise RuntimeError("MODEL_PATH_DAYS and MODEL_PATH_YIELD must be set")

    model_days = joblib.load(MODEL_PATH_DAYS)
    log.info("[MODEL:days] loaded from %s", MODEL_PATH_DAYS)

    model_yield = joblib.load(MODEL_PATH_YIELD)
    log.info("[MODEL:yield] loaded from %s", MODEL_PATH_YIELD)

    preproc = None
    if PREPROC_PATH and os.path.isfile(PREPROC_PATH):
        try:
            preproc = joblib.load(PREPROC_PATH)
            log.info("[PREPROC] loaded from %s", PREPROC_PATH)
        except Exception as exc:
            log.warning("[PREPROC] failed to load from %s: %s", PREPROC_PATH, exc)

    order = (
        _load_feature_order_from_meta()
        or feature_shim._expected_columns_from_preproc(preproc)
        or _infer_feature_order_from_model(model_days)
        or _infer_feature_order_from_model(model_yield)
    )

    src = (
        "meta" if order and FEATURE_META_PATH else
        "preproc" if order and preproc is not None else
        "model" if order else
        "none"
    )
    log.info("[FEATURES] order size=%s (source=%s)", len(order) if order else None, src)

    _ARTIFACTS = SimpleNamespace(
        model_days=model_days,
        model_yield=model_yield,
        model_path_days=MODEL_PATH_DAYS,
        model_path_yield=MODEL_PATH_YIELD,
        preproc=preproc,
        feature_order=order,
    )
    _SIG = sig
    return _ARTIFACTS


def _new_rid() -> str:
    return datetime.utcnow().strftime("%Y%m%d%H%M%S%f")[:-2]


def _ensure_rid(request: Request) -> str:
    rid = getattr(request.state, "request_id", None)
    if not rid:
        rid = _new_rid()
        try:
            request.state.request_id = rid
        except Exception:
            pass
    return rid


def _resolve_feature_order(art: SimpleNamespace) -> List[str]:
    if art.feature_order:
        return list(art.feature_order)
    names = getattr(art.preproc, "feature_names_in_", None)
    order = _safe_list(names)
    if order:
        return order
    raise RuntimeError("feature_order is empty")


def _apply_preproc(X: List[List[float]], feature_order: List[str], preproc):
    if preproc is None:
        return np.asarray(X, dtype=float)
    if pd is None:
        raise RuntimeError("pandas is required when a preprocessor is configured")
    df = pd.DataFrame(X, columns=feature_order)
    return preproc.transform(df)


def _parse_payload(body: Any) -> List[Dict[str, Any]]:
    if not isinstance(body, dict):
        raise ValueError("JSON root must be an object")
    if "records" in body or "X" in body or ("features" in body and not isinstance(body.get("data"), list)):
        raise ValueError("このAPIは data[*].features のみ対応")

    data = body.get("data")
    if not isinstance(data, list):
        raise ValueError("'data' must be a list")

    items: List[Dict[str, Any]] = []
    for idx, entry in enumerate(data):
        if not isinstance(entry, dict):
            raise ValueError(f"data[{idx}] must be an object")
        if "records" in entry or "X" in entry or ("features" not in entry):
            raise ValueError("このAPIは data[*].features のみ対応")
        feats = entry.get("features")
        if not isinstance(feats, dict):
            raise ValueError(f"data[{idx}].features must be an object")
        items.append(feats)

    if not items:
        raise ValueError("data must not be empty")
    return items


def _assert_required_in_feature_order(feature_order: List[str]) -> None:
    missing = [
        key for key in REQUIRED_TEMP_KEYS + REQUIRED_OTHER_KEYS if key not in feature_order
    ]
    if missing:
        raise RuntimeError(f"feature_order missing required keys: {missing}")


def _matrix_from_items(items: List[Dict[str, Any]], feature_order: List[str]) -> List[List[float]]:
    required = set(REQUIRED_TEMP_KEYS + REQUIRED_OTHER_KEYS)
    matrix: List[List[float]] = []
    for idx, feats in enumerate(items):
        row: List[float] = []
        for col in feature_order:
            value = feats.get(col, None)
            if value is None:
                if col in required:
                    raise ValueError(f"data[{idx}].features missing required key '{col}'")
                row.append(0.0)
                continue
            try:
                row.append(float(value))
            except (TypeError, ValueError):
                raise ValueError(f"data[{idx}].features['{col}'] must be numeric")
        matrix.append(row)
    return matrix


def _health_payload(request: Request) -> Dict[str, Any]:
    rid = _ensure_rid(request)
    try:
        art = get_model_and_artifacts()
        size = len(art.feature_order) if art.feature_order else None
        return {
            "ok": bool(art.model_days is not None and art.model_yield is not None),
            "model_path_days": art.model_path_days,
            "model_path_yield": art.model_path_yield,
            "request_id": rid,
            "feature_order_size": size,
        }
    except Exception:
        log.exception("health check failed (rid=%s)", rid)
        return {"ok": False, "error": {"code": 100, "message": "model_load_failed"}, "request_id": rid}


@router.get("/feature_meta")
async def feature_meta():
    art = get_model_and_artifacts()
    size = len(art.feature_order) if art.feature_order else 0
    return {"ok": True, "feature_order": art.feature_order, "feature_order_size": size}


@router.get("/health")
async def health(request: Request):
    return _health_payload(request)


@router.post("/predict")
async def predict(request: Request):
    rid = _ensure_rid(request)
    log.info("/predict called (rid=%s)", rid)

    try:
        body = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail={"code": 200, "message": "Invalid JSON payload"})

    try:
        features = _parse_payload(body)
        art = get_model_and_artifacts()
        feature_order = _resolve_feature_order(art)
        _assert_required_in_feature_order(feature_order)
        X = _matrix_from_items(features, feature_order)
        Xt = _apply_preproc(X, feature_order, art.preproc)
        days = np.rint(art.model_days.predict(Xt)).astype(int)
        predictions = [int(d) for d in days]
        return {
            "ok": True,
            "model_path": art.model_path_days,
            "request_id": rid,
            "predictions": predictions,
        }
    except ValueError as exc:
        raise HTTPException(status_code=400, detail={"code": 200, "message": str(exc)})
    except RuntimeError as exc:
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(exc)})
    except Exception:
        log.exception("predict failed (rid=%s)", rid)
        raise HTTPException(status_code=500, detail={"code": 900, "message": "internal error"})


@router.post("/predict_both")
async def predict_both(request: Request):
    rid = _ensure_rid(request)
    log.info("/predict_both called (rid=%s)", rid)

    try:
        body = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail={"code": 200, "message": "Invalid JSON payload"})

    try:
        features = _parse_payload(body)
        art = get_model_and_artifacts()
        feature_order = _resolve_feature_order(art)
        _assert_required_in_feature_order(feature_order)
        X = _matrix_from_items(features, feature_order)
        Xt = _apply_preproc(X, feature_order, art.preproc)
        days = np.rint(art.model_days.predict(Xt)).astype(int)
        yields = art.model_yield.predict(Xt)
        predictions = [
            {"days": int(d), "yield": float(y)} for d, y in zip(days, yields)
        ]
        return {
            "ok": True,
            "model_path_days": art.model_path_days,
            "model_path_yield": art.model_path_yield,
            "request_id": rid,
            "predictions": predictions,
        }
    except ValueError as exc:
        raise HTTPException(status_code=400, detail={"code": 200, "message": str(exc)})
    except RuntimeError as exc:
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(exc)})
    except Exception:
        log.exception("predict_both failed (rid=%s)", rid)
        raise HTTPException(status_code=500, detail={"code": 900, "message": "internal error"})
