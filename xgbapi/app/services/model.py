import os
import joblib
from typing import Any, Tuple


# XGBoost は遅延 import（環境により未インストール期の起動軽減）
def _load_xgb_json(path: str):
    from xgboost import Booster
    booster = Booster()
    booster.load_model(path)
    return booster


def load_model(model_path: str) -> Tuple[str, Any]:
    """
    Returns:
        (model_type, handle)
        model_type: 'xgb-json' or 'sk-pipeline'
    """
    ext = os.path.splitext(model_path)[1].lower()
    if ext == ".json":
        return "xgb-json", _load_xgb_json(model_path)
    elif ext in (".pkl", ".joblib"):
        return "sk-pipeline", joblib.load(model_path)
    else:
        raise RuntimeError(f"Unsupported model file: {model_path}")


def predict_any(model_tuple: Tuple[str, Any], features: dict) -> Tuple[float, int, str]:
    """
    与えられた特徴量dictから (yield_kg, days, version) を返す。
    ここはプロジェクト固有ロジックで調整してください。
    """
    model_type, handle = model_tuple

    # 例: 特徴量ベクトル化（必要に応じて整形）
    # 必要なら columns 順序の保証・欠損補完をここに。
    X = [[
        features["area_m2"],
        features["harvest_partial_ratio"],
        features.get("rc_avg_yield", 0.0),
        features.get("rc_avg_days", 0.0),
        features.get("rc_n", 0.0),
        features["calendar_week_target"],
    ]]

    if model_type == "xgb-json":
        import numpy as np
        from xgboost import DMatrix
        dmat = DMatrix(np.array(X))
        y = handle.predict(dmat)
        # 例: 1つ目を収量(kg)とし、日数は単純規則や別モデル等
        y_kg = float(y[0])
        y_days = int(round(50 + (1.0 - features["harvest_partial_ratio"]) * 5))  # ダミー
        version = "xgb-json"
    else:
        y = handle.predict(X)
        y_kg = float(y[0])
        y_days = int(round(50))  # ダミー
        version = getattr(handle, "version_", None) or "sk-pipeline"

    return y_kg, y_days, version
