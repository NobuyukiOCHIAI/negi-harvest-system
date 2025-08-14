from __future__ import annotations
import os
from pathlib import Path
import joblib
import pickle

DEFAULT_MODEL_DIR = "/home/centos/xgbapi/models/yield_days_xgb/2025-08-11_1600"

def get_model_dir() -> Path:
    d = os.getenv("MODEL_DIR", DEFAULT_MODEL_DIR)
    return Path(d).expanduser().resolve()

def _load_pickle(p: Path):
    # joblib優先。失敗時はpickleにフォールバック
    try:
        return joblib.load(p)
    except Exception:
        with p.open("rb") as f:
            return pickle.load(f)

def load_models() -> dict:
    """
    必要3ファイル:
      - model_yield.pkl
      - model_days.pkl
      - preproc.pkl
    """
    mdir = get_model_dir()
    need = ["model_yield.pkl", "model_days.pkl", "preproc.pkl"]
    missing = [n for n in need if not (mdir / n).is_file()]
    if missing:
        raise FileNotFoundError(f"MODEL_DIR={mdir} missing files: {missing}")

    models = {
        "model_yield": _load_pickle(mdir / "model_yield.pkl"),
        "model_days":  _load_pickle(mdir / "model_days.pkl"),
        "preproc":     _load_pickle(mdir / "preproc.pkl"),
        "model_dir":   str(mdir),
    }
    # バージョン情報（任意）
    models["model_version"] = os.getenv("MODEL_VERSION", mdir.name)
    return models
