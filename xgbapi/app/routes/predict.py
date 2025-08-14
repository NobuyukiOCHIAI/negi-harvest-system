import os
from datetime import datetime
from fastapi import APIRouter, Depends, HTTPException, status
from ..deps.auth import require_api_key
from ..schemas import PredictRequest, PredictResponse
from ..services.model import load_model, predict_any

router = APIRouter()

# グローバルにモデルを1度だけロード
_MODEL_TUPLE = None

def _ensure_model_loaded():
    global _MODEL_TUPLE
    if _MODEL_TUPLE is None:
        model_path = os.getenv("MODEL_PATH")
        if not model_path:
            raise RuntimeError("MODEL_PATH is not set")
        _MODEL_TUPLE = load_model(model_path)
    return _MODEL_TUPLE


@router.post("/predict", response_model=PredictResponse)
def post_predict(payload: PredictRequest, _=Depends(require_api_key)):
    """
    重要: embed=True を使わず、素の JSON を受ける。
    例の curl:
      curl -H 'Content-Type: application/json' -H "x-api-key: $API_KEY" \
        --data-binary @/tmp/payload.json http://127.0.0.1:8080/predict
    """
    try:
        mt = _ensure_model_loaded()

        # recent_cycle_refs の代表値（例）
        rc_avg_yield = rc_avg_days = rc_n = 0.0
        if payload.recent_cycle_refs:
            # 単純に先頭を使う（必要に応じて平均や重み付けへ）
            r0 = payload.recent_cycle_refs[0]
            rc_avg_yield = float(r0.avg_yield)
            rc_avg_days = float(r0.avg_days)
            rc_n = float(r0.n)

        # 特徴量 dict 作成（本番ロジックに合わせて調整可能）
        feats = {
            "area_m2": payload.area_m2,
            "harvest_partial_ratio": payload.harvest_partial_ratio,
            "rc_avg_yield": rc_avg_yield,
            "rc_avg_days": rc_avg_days,
            "rc_n": rc_n,
            "calendar_week_target": payload.calendar_week_target,
            # 例: 日付特徴などをここで追加しても良い
        }

        y_kg, y_days, version = predict_any(mt, feats)

        return PredictResponse(
            ok=True,
            predicted_yield_kg=y_kg,
            predicted_days=y_days,
            model_version=version,
            inputs_echo={
                "bed_id": payload.bed_id,
                "group": payload.group,
                "sowing_date": str(payload.sowing_date),
                "transplant_date": str(payload.transplant_date),
                "area_m2": payload.area_m2,
                "harvest_partial_ratio": payload.harvest_partial_ratio,
                "calendar_week_target": payload.calendar_week_target,
                "now": payload.now.isoformat(),
            },
        )
    except HTTPException:
        raise
    except Exception as e:
        # 500 で握る
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))
