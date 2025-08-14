from datetime import date, datetime
from typing import List
from pydantic import BaseModel, Field, ConfigDict, field_validator


class RecentCycleRef(BaseModel):
    avg_yield: float = Field(..., ge=0)
    avg_days: int = Field(..., ge=1)
    n: int = Field(..., ge=1)


class PredictRequest(BaseModel):
    bed_id: str
    group: str
    sowing_date: date
    transplant_date: date
    area_m2: float = Field(..., gt=0)
    harvest_partial_ratio: float = Field(..., ge=0.0, le=1.0)
    recent_cycle_refs: List[RecentCycleRef] = Field(default_factory=list)
    calendar_week_target: int = Field(..., ge=1, le=53)
    now: datetime

    @field_validator("group")
    @classmethod
    def validate_group(cls, v: str) -> str:
        # 必要に応じてホワイトリストを拡張
        allowed = {"normal", "late", "early"}
        if v not in allowed:
            raise ValueError(f"group must be one of {sorted(allowed)}")
        return v


class PredictResponse(BaseModel):
    model_config = ConfigDict(protected_namespaces=())  # 'model_' 警告の抑止用
    ok: bool
    predicted_yield_kg: float
    predicted_days: int
    model_version: str | None = None
    inputs_echo: dict | None = None
