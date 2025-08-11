import os
import joblib
from pathlib import Path
from typing import Optional

class ModelBundle:
    def __init__(self, ok: bool, reason: Optional[str] = None):
        self.ok = ok
        self.reason = reason
        self.preproc = None
        self.model_yield = None
        self.model_days = None
        self.model_path = None

def load_models() -> ModelBundle:
    name = os.getenv("MODEL_NAME", "yield_days_xgb")
    ver  = os.getenv("MODEL_VERSION", "2025-08-10_001")
    base_env = os.getenv("MODEL_BASE")
    default_base = Path(__file__).resolve().parents[2] / "models"
    base = Path(base_env).expanduser() if base_env else default_base
    model_dir = base / name / ver

    mb = ModelBundle(ok=False)
    mb.model_path = str(model_dir)

    try:
        preproc = model_dir / "preproc.pkl"
        myield  = model_dir / "model_yield.pkl"
        mdays   = model_dir / "model_days.pkl"

        if preproc.exists() and myield.exists() and mdays.exists():
            mb.preproc = joblib.load(preproc)
            mb.model_yield = joblib.load(myield)
            mb.model_days = joblib.load(mdays)
            mb.ok = True
        else:
            mb.reason = f"Model files missing in {model_dir}"
    except Exception as e:
        mb.reason = f"Load error: {e}"

    return mb
