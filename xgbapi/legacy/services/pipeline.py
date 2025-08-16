from datetime import datetime, timezone
import pandas as pd

# 必要に応じて zoneinfo で JST を扱うことも可能
JST = timezone.utc  # 簡易設定。必要なら zoneinfo で Asia/Tokyo を適用

def to_jst(dt_str: str | None):
    if not dt_str:
        return None
    # "YYYY-MM-DD" or ISO8601 を許容
    return datetime.fromisoformat(dt_str.replace("Z","+00:00"))

def build_features(payload: dict) -> pd.DataFrame:
    # payload からモデル入力用のDataFrameを作る
    trans_date = payload.get("transplant_date")
    sow_date   = payload.get("sowing_date")
    now_str    = payload.get("now")
    group      = payload.get("group", "normal")
    area_m2    = payload.get("area_m2", 0.0)
    partial    = payload.get("harvest_partial_ratio", 0.0)

    # 特徴量例（学習時の前処理と合わせて必要に応じ拡張）
    df = pd.DataFrame([{
        "transplant_month": int(trans_date.split("-")[1]) if trans_date else None,
        "days_since_transplant": None,
        "group_betakku": 1 if group.lower() == "betakku" else 0,
        "area_m2": area_m2,
        "partial_ratio": partial,
    }])

    if trans_date and now_str:
        try:
            dt_t = to_jst(trans_date)
            dt_n = to_jst(now_str)
            if dt_t and dt_n:
                df.loc[0, "days_since_transplant"] = (dt_n.date() - dt_t.date()).days
        except Exception:
            pass

    return df
