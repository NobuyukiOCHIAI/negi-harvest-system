from __future__ import annotations

from pydantic import BaseModel, Field
from typing import Any, Dict, List, Optional, Union


class _Base(BaseModel):
    """Base model with shared configuration."""

    model_config = {
        "populate_by_name": True,
        "protected_namespaces": (),
    }


class PredictItem(_Base):
    features: Union[Dict[str, Any], List[float]]


class PredictRequest(_Base):
    data: List[PredictItem]


class PredictResponse(_Base):
    ok: bool
    model_path: Optional[str] = None
    request_id: Optional[str] = None
    predictions: List[float]


class PredictBothRequest(PredictRequest):
    model_config = {
        **_Base.model_config,
        "json_schema_extra": {
            "example": {
                "data": [
                    {
                        "features": {
                            "育苗日数": 21,
                            "定植月": 8,
                            "グループ_通常": 1,
                            "気温_平均": 28.3,
                            "気温_最大": 33.1,
                            "気温_最小": 24.9,
                            "気温_std": 2.1,
                            "気温振れ幅_平均": 6.2,
                            "気温振れ幅_std": 1.4,
                            "類似ベッド_平均収量": 120,
                            "類似ベッド_平均日数": 52,
                            "前年同時期収量": 110,
                            "前年同時期日数": 55,
                            "収量差_前年": 10,
                            "日数差_前年": -3,
                            "営業調整日数": 0
                        }
                    }
                ]
            }
        },
    }


class PredictBothItem(_Base):
    days: float
    yield_: float = Field(alias="yield")


class PredictBothResponse(_Base):
    ok: bool
    model_path_days: Optional[str] = None
    model_path_yield: Optional[str] = None
    request_id: Optional[str] = None
    predictions: List[PredictBothItem]

    model_config = {
        **_Base.model_config,
        "json_schema_extra": {
            "example": {
                "ok": True,
                "model_path_days": "/home/centos/xgbapi/models/yield_days_xgb/2025-08-11_1600/model_days.pkl",
                "model_path_yield": "/home/centos/xgbapi/models/yield_days_xgb/2025-08-11_1600/model_yield.pkl",
                "request_id": "20250814101334133618",
                "predictions": [
                    {"days": 52.30755615234375, "yield": 149.63699340820312}
                ]
            }
        },
    }
