# ─────────────────────────────────────────────────────────────
# File: routes/predict.py
# Purpose: Prediction endpoints (days & yield) with:
#          - feature-order alignment (meta / preproc / model)
#          - optional preprocessing (DataFrame-based)
#          - backward-compatible single-model /predict
#          - new dual-model /predict_both (days + yield)
# ─────────────────────────────────────────────────────────────

from __future__ import annotations

import os
import json
import logging
import traceback
from datetime import datetime
from types import SimpleNamespace
from typing import Any, Dict, List, Optional, Union

from fastapi import APIRouter, HTTPException, Request

from app.schemas_predict import (
    PredictItem,
    PredictRequest,
    PredictResponse,
    PredictBothRequest,
    PredictBothResponse,
    PredictBothItem,
)
from app.services import feature_shim

# Required
try:
    import joblib
except Exception as e:
    raise RuntimeError("joblib is required to load model .pkl files") from e

# Optional (used if preprocessor is present / dict→DataFrame 整形に必要)
try:
    import pandas as pd  # type: ignore
except Exception:  # pragma: no cover
    pd = None

# Optional (list入力の高速化などに使用)
try:
    import numpy as np  # type: ignore
except Exception:  # pragma: no cover
    np = None

router = APIRouter()
log = logging.getLogger("xgbapi.predict")

# ── Environment ──────────────────────────────────────────────
MODEL_PATH_DAYS = os.getenv("MODEL_PATH_DAYS")     # e.g. /.../model_days.pkl
MODEL_PATH_YIELD = os.getenv("MODEL_PATH_YIELD")   # e.g. /.../model_yield.pkl
PREPROC_PATH = os.getenv("PREPROC_PATH")           # e.g. /.../preproc.pkl
FEATURE_META_PATH = os.getenv("FEATURE_META_PATH") # e.g. /.../feature_meta.json

# ── Artifacts cache ─────────────────────────────────────────
_ARTIFACTS: Optional[SimpleNamespace] = None
_SIG: Optional[str] = None


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


# ── Feature-order helpers ───────────────────────────────────
def _safe_list(x) -> Optional[List[str]]:
    if x is None:
        return None
    try:
        lst = list(x)
        return [str(v) for v in lst]
    except Exception:
        return None


def _load_json(path: str) -> Any:
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def _load_feature_order_from_meta() -> Optional[List[str]]:
    if not FEATURE_META_PATH or not os.path.isfile(FEATURE_META_PATH):
        return None
    try:
        meta = _load_json(FEATURE_META_PATH)
        # 許容キー：feature_order / feature_names / columns / feature_cols
        for key in ("feature_order", "feature_names", "columns", "feature_cols"):
            arr = _safe_list(meta.get(key))
            if arr and all(isinstance(s, str) for s in arr):
                return arr
    except Exception:
        log.exception("Failed to load FEATURE_META_PATH=%s", FEATURE_META_PATH)
    return None


def _infer_feature_order_from_model(model) -> Optional[List[str]]:
    if model is None:
        return None
    # sklearn estimators: feature_names_in_
    arr = _safe_list(getattr(model, "feature_names_in_", None))
    if arr and all(isinstance(s, str) for s in arr):
        return arr
    # xgboost booster
    if hasattr(model, "get_booster"):
        try:
            booster = model.get_booster()
            arr = _safe_list(getattr(booster, "feature_names", None))
            if arr and all(isinstance(s, str) for s in arr):
                return arr
        except Exception:
            pass
    return None


# ── Artifacts loader ────────────────────────────────────────
def get_model_and_artifacts() -> SimpleNamespace:
    """Load/Cache: model_days, model_yield, preproc, feature_order, model paths."""
    global _ARTIFACTS, _SIG

    sig = _signature()
    if _ARTIFACTS is not None and sig == _SIG:
        return _ARTIFACTS

    if not MODEL_PATH_DAYS or not MODEL_PATH_YIELD:
        raise RuntimeError("MODEL_PATH_DAYS and MODEL_PATH_YIELD must be set")

    model_path_days = MODEL_PATH_DAYS
    model_path_yield = MODEL_PATH_YIELD

    model_days = joblib.load(model_path_days)
    log.info("[MODEL:days] loaded from %s", model_path_days)

    model_yield = joblib.load(model_path_yield)
    log.info("[MODEL:yield] loaded from %s", model_path_yield)

    preproc = None
    if PREPROC_PATH and os.path.isfile(PREPROC_PATH):
        try:
            preproc = joblib.load(PREPROC_PATH)
            log.info("[PREPROC] loaded from %s", PREPROC_PATH)
        except Exception as e:
            log.warning("[PREPROC] failed to load from %s: %s", PREPROC_PATH, e)

    # 列順の決定：meta → preproc → days → yield
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
        model_path_days=model_path_days,
        model_path_yield=model_path_yield,
        preproc=preproc,
        feature_order=order,
    )
    _SIG = sig
    return _ARTIFACTS


# ── Helpers ────────────────────────────────────────────────
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


def _records_to_matrix(items: List[PredictItem], feature_order: Optional[List[str]]) -> List[List[float]]:
    """
    - dict 入力: feature_order に合わせて整列。欠損は 0.0 補完（必要なら np.nan に変更可）
    - list 入力: 長さチェックのみ（feature_order 未知でも受け入れる）
    """
    X: List[List[float]] = []

    any_dict = any(isinstance(it.features, dict) for it in items)
    if any_dict:
        if feature_order is None:
            raise ValueError("Dictionary features require feature_order; set FEATURE_META_PATH or use array inputs")
        for i, it in enumerate(items):
            if isinstance(it.features, dict):
                row_src = it.features
                row = [float(row_src.get(col, 0.0)) for col in feature_order]
                X.append(row)
            elif isinstance(it.features, list):
                if len(it.features) != len(feature_order):
                    raise ValueError(f"Feature shape mismatch, expected: {len(feature_order)}, got {len(it.features)} (index={i})")
                X.append([float(x) for x in it.features])
            else:
                raise TypeError(f"Unsupported feature type at index={i}: {type(it.features)}")
        return X

    # すべて list 入力
    expected = None
    for i, it in enumerate(items):
        if not isinstance(it.features, list):
            raise ValueError("Mixed features; all list or all dict")
        if expected is None:
            expected = len(it.features)
        elif len(it.features) != expected:
            raise ValueError(f"Inconsistent feature length at index={i}: got {len(it.features)}, expected {expected}")
        X.append([float(x) for x in it.features])
    return X


def _apply_preproc(X: List[List[float]], feature_order: Optional[List[str]], preproc):
    """preproc がある場合のみ DataFrame を作って transform"""
    if preproc is None:
        return X
    if pd is None:
        raise RuntimeError("pandas not available but PREPROC_PATH is set; install pandas or remove preprocessor")
    if feature_order is None:
        raise RuntimeError("feature_order is required to apply preprocessor; provide FEATURE_META_PATH or preproc/model names")
    df = pd.DataFrame(X, columns=feature_order)
    return preproc.transform(df)


# ── Endpoints ──────────────────────────────────────────────
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


@router.post("/predict", response_model=PredictResponse)
async def predict(req: PredictRequest, request: Request):
    """
    従来互換：単一モデル（days）で推論
    """
    rid = _ensure_rid(request)
    log.info("/predict called (rid=%s) records=%d", rid, len(req.data))

    try:
        art = get_model_and_artifacts()
        if art.model_days is None:
            raise RuntimeError("MODEL_PATH_DAYS is not set or days model failed to load.")
    except Exception as e:
        log.exception("Model load error (rid=%s)", rid)
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(e)})

    try:
        X = _records_to_matrix(req.data, art.feature_order)
    except ValueError as e:
        raise HTTPException(status_code=400, detail={"code": 200, "message": str(e)})

    try:
        Xt = _apply_preproc(X, art.feature_order, art.preproc)
        preds = art.model_days.predict(Xt)
        try:
            predictions = [float(x) for x in preds]
        except Exception:
            predictions = [float(x) for x in list(preds)]
        return {
            "ok": True,
            "model_path": art.model_path_days,
            "request_id": rid,
            "predictions": predictions,
        }
    except Exception as e:
        tb = traceback.format_exc()
        log.error("Prediction failed (rid=%s): %s\n%s", rid, e, tb)
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(e)})


@router.post("/predict_both", response_model=PredictBothResponse)
async def predict_both(req: PredictBothRequest, request: Request):
    """
    同一の特徴量から 日数(days) と 収量(yield) を同時に推論
    """
    rid = _ensure_rid(request)
    log.info("/predict_both called (rid=%s) records=%d", rid, len(req.data))

    try:
        art = get_model_and_artifacts()
        if art.model_days is None:
            raise RuntimeError("MODEL_PATH_DAYS is not set or days model failed to load.")
        if art.model_yield is None:
            raise RuntimeError("MODEL_PATH_YIELD is not set or yield model failed to load.")
    except Exception as e:
        log.exception("Model load error (rid=%s)", rid)
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(e)})

    try:
        X = _records_to_matrix(req.data, art.feature_order)
    except ValueError as e:
        raise HTTPException(status_code=400, detail={"code": 200, "message": str(e)})

    try:
        Xt = _apply_preproc(X, art.feature_order, art.preproc)

        y_days = art.model_days.predict(Xt)
        y_yield = art.model_yield.predict(Xt)

        items: List[PredictBothItem] = []
        for d, y in zip(y_days, y_yield):
            items.append(PredictBothItem(days=float(d), yield_=float(y)))

        return PredictBothResponse(
            ok=True,
            model_path_days=art.model_path_days,
            model_path_yield=art.model_path_yield,
            request_id=rid,
            predictions=items,
        )
    except Exception as e:
        tb = traceback.format_exc()
        log.error("PredictBoth failed (rid=%s): %s\n%s", rid, e, tb)
        raise HTTPException(status_code=500, detail={"code": 100, "message": str(e)})
