# -*- coding: utf-8 -*-
"""
前処理とモデルの食い違いを可視化するテスター
- preproc.transform での列不一致/型エラーを詳細に表示
- transform後のshape / feature_names を表示（取得できる場合）
- yield/day 両モデルでのpredictを試す
"""

from __future__ import annotations
from pathlib import Path
import sys, traceback, importlib, json
import pandas as pd

# ==== ここをあなたの環境に合わせてください ====
MODEL_DIR = Path(r"G:\マイドライブ\GF落合\forecast\xgbapi\models\yield_days_xgb\2025-08-11_1600")
# =============================================

def _load(p: Path):
    # joblib優先、失敗したらpickle
    import joblib, pickle
    try:
        return joblib.load(p)
    except Exception:
        with p.open("rb") as f:
            return pickle.load(f)

def _print_versions():
    import sklearn, xgboost, numpy, pandas
    print("=== versions ===")
    print("python :", sys.version.split()[0])
    print("sklearn:", sklearn.__version__)
    print("xgboost:", xgboost.__version__)
    print("numpy  :", numpy.__version__)
    print("pandas :", pandas.__version__)
    print("================")

def _find_column_transformer(preproc):
    """Pipeline の中から ColumnTransformer を探す（無ければ None）"""
    from sklearn.pipeline import Pipeline
    from sklearn.compose import ColumnTransformer
    if isinstance(preproc, ColumnTransformer):
        return preproc
    if isinstance(preproc, Pipeline):
        for name, step in preproc.steps:
            if isinstance(step, ColumnTransformer):
                return step
            # ネストしている場合も探索
            ct = _find_column_transformer(step)
            if ct is not None:
                return ct
    return None

def _expected_columns(preproc):
    cols = []
    # sklearn 1.0+ では feature_names_in_ がある場合あり
    for attr in ("feature_names_in_",):
        if hasattr(preproc, attr):
            try:
                return list(getattr(preproc, attr))
            except Exception:
                pass
    # ColumnTransformer の transformers_ から入力列候補を推測
    ct = _find_column_transformer(preproc)
    if ct is not None and hasattr(ct, "transformers"):
        for name, trans, cols_ in ct.transformers:
            if cols_ == "drop":
                continue
            if cols_ == "passthrough":
                # 事前に別途設定されている可能性。ここではスキップ
                continue
            if isinstance(cols_, (list, tuple)):
                cols.extend([str(c) for c in cols_])
    return list(dict.fromkeys(cols))  # 重複排除

def _run_case(title: str, payload: dict, preproc, model_yield, model_days):
    print(f"\n--- CASE: {title} ---")
    df = pd.DataFrame([payload])
    print("raw columns:", df.columns.tolist())

    try:
        Xt = preproc.transform(df)
        shape = getattr(Xt, "shape", None)
        print("preproc.transform: OK, shape:", shape, type(Xt))
        # 特徴量名（取れれば）
        names = None
        if hasattr(preproc, "get_feature_names_out"):
            try:
                names = preproc.get_feature_names_out()
            except Exception:
                names = None
        if names is not None:
            print("feature_names_out (head):", list(names)[:20], "... total:", len(names))
        # 予測
        y = model_yield.predict(Xt)
        d = model_days.predict(Xt)
        print("predict: OK")
        print("  yield:", getattr(y, "tolist", lambda: y)())
        print("  days :", getattr(d, "tolist", lambda: d)())
    except Exception as e:
        print("ERROR during transform/predict:", e.__class__.__name__, e)
        # 期待列のヒント
        exp = _expected_columns(preproc)
        if exp:
            print(">>> expected input columns (hint):", exp)
        print(">>> traceback:")
        traceback.print_exc()

def main():
    _print_versions()

    assert MODEL_DIR.is_dir(), f"MODEL_DIR not found: {MODEL_DIR}"
    preproc = _load(MODEL_DIR / "preproc.pkl")
    model_yield = _load(MODEL_DIR / "model_yield.pkl")
    model_days  = _load(MODEL_DIR / "model_days.pkl")
    print("loaded:", "preproc.pkl / model_yield.pkl / model_days.pkl from", MODEL_DIR)

    # API の “最小” に近いケース（あえて recent_cycle_refs なし）
    case_min = {
        "bed_id": "S-1-1",
        "group": "normal",
        "sowing_date": "2025-06-10",
        "transplant_date": "2025-07-01",
        "area_m2": 12.0,
        "harvest_partial_ratio": 0.0,
        "calendar_week_target": 33,
    }

    # ハンドオフの “安全版” に近いケース（複合欄除外 or 平均に要約）
    # recent_cycle_refs をそのまま渡すと preproc が想定外のことが多いので、
    # ここでは平均値に要約して単一スカラーに落とす例を用意（要件に合わせて調整）
    refs = [
        { "avg_yield": 120.0, "avg_days": 55, "n": 5 },
        { "avg_yield": 110.0, "avg_days": 52, "n": 7 }
    ]
    # 簡易要約（荷重平均）— preproc がこのスカラー列を期待している場合に合わせる
    import math
    w = sum(r.get("n", 0) or 0 for r in refs) or 1
    wy = sum((r.get("avg_yield") or 0) * (r.get("n") or 0) for r in refs) / w
    wd = sum((r.get("avg_days")  or 0) * (r.get("n") or 0) for r in refs) / w

    case_safe = dict(case_min, **{
        "recent_avg_yield": wy,
        "recent_avg_days":  wd,
        "recent_n":         w,
    })

    _run_case("MIN", case_min, preproc, model_yield, model_days)
    _run_case("SAFE", case_safe, preproc, model_yield, model_days)

if __name__ == "__main__":
    main()
