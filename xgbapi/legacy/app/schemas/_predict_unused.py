from pydantic import BaseModel, Field
from typing import Optional, List

class RecentCycleRef(BaseModel):
    avg_yield: float
    avg_days: int
    n: int

class PredictRequest(BaseModel):
    bed_id: str
    group: str = Field(default="normal")
    sowing_date: Optional[str] = None
    transplant_date: Optional[str] = None
    area_m2: float = 0.0
    harvest_partial_ratio: float = 0.0
    recent_cycle_refs: Optional[List[RecentCycleRef]] = None
    calendar_week_target: Optional[float] = None
    now: Optional[str] = None

class PredictResponse(BaseModel):
    bed_id: str
    predicted_yield_kg: float
    predicted_days: int
    predicted_first_harvest_date: Optional[str] = None
    confidence: dict
    model_version: str
    features_debug: dict
