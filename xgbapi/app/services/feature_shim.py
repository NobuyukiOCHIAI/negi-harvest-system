# /home/centos/xgbapi/app/services/feature_shim.py
from __future__ import annotations
from datetime import datetime, date
from typing import Any, Dict, List, Optional, Iterable
import numpy as np
import pandas as pd

# =========================================================
# 基本ユーティリティ
# =========================================================

def _parse_date(v: Any) -> Optional[date]:
    """ISO文字列/日付相当を date に。失敗したら None。"""
    if v in (None, ""):
        return None
    try:
        return datetime.fromisoformat(str(v)).date()
    except Exception:
        return None

def _expected_columns_from_preproc(preproc) -> List[str]:
    """
    preproc（学習時の前処理パイプライン）が想定する「入力列名」を推定。
    - 最優先: feature_names_in_
    - 代替   : Pipeline / ColumnTransformer 走査で候補収集
    """
    # sklearn >= 1.0
    if hasattr(preproc, "feature_names_in_"):
        try:
            cols = list(preproc.feature_names_in_)
            if cols:
                return cols
        except Exception:
            pass

    # ColumnTransformer探索
    try:
        from sklearn.pipeline import Pipeline
        from sklearn.compose import ColumnTransformer
    except Exception:
        Pipeline = None
        ColumnTransformer = None

    def _find_ct(obj):
        if ColumnTransformer is not None and isinstance(obj, ColumnTransformer):
            return obj
        if Pipeline is not None and isinstance(obj, Pipeline):
            for _, step in obj.steps:
                ct = _find_ct(step)
                if ct is not None:
                    return ct
        return None

    cols: List[str] = []
    ct = _find_ct(preproc)
    if ct is not None and hasattr(ct, "transformers"):
        for _, _, cols_ in getattr(ct, "transformers", []):
            if cols_ in ("drop", "passthrough"):
                continue
            if isinstance(cols_, (list, tuple)):
                cols.extend([str(c) for c in cols_])

    # 重複排除
    out: List[str] = []
    seen = set()
    for c in cols:
        if c not in seen:
            out.append(c)
            seen.add(c)
    return out

def _weighted_avg(items: Iterable[Dict[str, Any]], key: str, weight: str = "n") -> Optional[float]:
    """
    items[*][key] を items[*][weight] で加重平均。欠損は無視。
    例: key='avg_days', weight='n'
    """
    wsum = 0.0
    asum = 0.0
    any_valid = False
    for it in items or []:
        v = it.get(key, None)
        w = it.get(weight, 0) or 0
        if v is None:
            continue
        try:
            vv = float(v)
            ww = float(w)
        except Exception:
            continue
        any_valid = True
        if ww > 0:
            wsum += ww
            asum += vv * ww
    if not any_valid or wsum <= 0:
        return None
    return asum / wsum

# =========================================================
# ドメイン派生：営業調整日数（自動計算）
# =========================================================

def _auto_business_adjust_days(payload: Dict[str, Any]) -> Optional[float]:
    """
    営業調整日数 = (収穫日 - 定植日) - 基準日数
      - 実績があれば actual_harvest_date 優先
      - 無ければ、既存の “計画収穫日ロジック”で payload 側が算出し格納した値を利用（_planned_harvest_date）
      - さらに無ければ、calendar_week_target から代表曜日=水曜で近似（既存踏襲）
      - 基準日数は recent_cycle_refs[*].avg_days の n 加重平均
    """
    tra_dt = _parse_date(payload.get("transplant_date"))
    if tra_dt is None:
        return None

    baseline_days = _weighted_avg(payload.get("recent_cycle_refs") or [], "avg_days", "n")
    if baseline_days is None or baseline_days <= 0:
        return None

    # 実績優先
    act_dt = _parse_date(payload.get("actual_harvest_date"))
    if act_dt:
        return float((act_dt - tra_dt).days - baseline_days)

    # 既存ロジックで計画収穫日を算出済みなら使う（_planned_harvest_date）
    phd_dt = _parse_date(payload.get("_planned_harvest_date"))
    if phd_dt:
        return float((phd_dt - tra_dt).days - baseline_days)

    # フォールバック：calendar_week_target → 代表曜日（水曜=3）
    cw = payload.get("calendar_week_target")
    if cw:
        try:
            planned = date.fromisocalendar(tra_dt.year, int(cw), 3)  # Wed=3
            return float((planned - tra_dt).days - baseline_days)
        except Exception:
            return None

    return None

# =========================================================
# 生入力 → 日本語特徴列（1レコード）
# =========================================================

def build_feature_row(payload: Dict[str, Any], preproc) -> pd.DataFrame:
    """
    APIの生入力(dict/Pydantic)を、preproc が期待する“学習時の日本語特徴列”に合わせて
    1行DataFrameとして構築。算出不能な列は NaN（＝Imputer に委任）。
    """
    # Pydantic v2: model_dump / v1: dict
    if hasattr(payload, "model_dump"):
        payload = payload.model_dump()
    elif hasattr(payload, "dict"):
        payload = payload.dict()

    expected = _expected_columns_from_preproc(preproc)
    row: Dict[str, Any] = {col: np.nan for col in expected}

    # --- 基本派生 ---
    sow_dt = _parse_date(payload.get("sowing_date"))
    tra_dt = _parse_date(payload.get("transplant_date"))
    if "育苗日数" in row and sow_dt and tra_dt:
        row["育苗日数"] = (tra_dt - sow_dt).days
    if "定植月" in row and tra_dt:
        row["定植月"] = tra_dt.month

    # --- グループ One-Hot の最低限 ---
    grp = (payload.get("group") or "").strip().lower()
    if "グループ_通常" in row:
        row["グループ_通常"] = 1 if grp in ("normal", "通常") else 0
    # 学習時に他の one-hot がある場合は必要に応じて追加：
    # if "グループ_短期" in row: row["グループ_短期"] = 1 if grp in ("short","短期") else 0
    # if "グループ_長期" in row: row["グループ_長期"] = 1 if grp in ("long","長期") else 0

    # --- 類似ベッド（recent_cycle_refs）荷重平均 ---
    refs = payload.get("recent_cycle_refs") or []
    wa_y = _weighted_avg(refs, "avg_yield", "n")
    wa_d = _weighted_avg(refs, "avg_days", "n")
    if "類似ベッド_平均収量" in row and wa_y is not None:
        row["類似ベッド_平均収量"] = wa_y
    if "類似ベッド_平均日数" in row and wa_d is not None:
        row["類似ベッド_平均日数"] = wa_d

    # --- 営業調整日数（自動計算） ---
    if "営業調整日数" in row:
        adj = _auto_business_adjust_days(payload)
        if adj is not None:
            row["営業調整日数"] = adj

    # そのほか、気温・前年比較など外部参照が必要な列は NaN のまま（Imputer に委任）

    # DataFrame（列順は expected に合わせる）
    if expected:
        return pd.DataFrame([[row.get(c, np.nan) for c in expected]], columns=expected)
    # expected が空で取れなかった場合の最終手段
    return pd.DataFrame([row])

# =========================================================
# 既存の特徴量DF → 日本語特徴列へ合わせる（複数行もOK）
# =========================================================

def adapt_features(df: pd.DataFrame, preproc, payload: Dict[str, Any] | None = None) -> pd.DataFrame:
    """
    既存の features DataFrame（例：pipeline.build_features の出力）を、
    preproc が期待する“日本語特徴列”に合わせて不足列を追加・並べ替えする。
    payload があれば、算出可能な列（育苗日数/定植月/営業調整日数/類似ベッド平均など）を埋める。
    """
    expected = _expected_columns_from_preproc(preproc)
    out = df.copy()

    # payload から算出できる列は計算して埋める（列が存在する/期待される場合）
    p = payload
    if p is not None and hasattr(p, "model_dump"):
        p = p.model_dump()
    elif p is not None and hasattr(p, "dict"):
        p = p.dict()

    if expected:
        # 不足列は NaN で追加
        for col in expected:
            if col not in out.columns:
                out[col] = np.nan

        # 可能な列は埋める（単一レコード想定 or 全行同値として埋める）
        if p is not None:
            sow_dt = _parse_date(p.get("sowing_date"))
            tra_dt = _parse_date(p.get("transplant_date"))
            if "育苗日数" in out.columns and sow_dt and tra_dt:
                out["育苗日数"] = (tra_dt - sow_dt).days
            if "定植月" in out.columns and tra_dt:
                out["定植月"] = tra_dt.month

            grp = (p.get("group") or "").strip().lower()
            if "グループ_通常" in out.columns:
                out["グループ_通常"] = 1 if grp in ("normal", "通常") else 0

            refs = p.get("recent_cycle_refs") or []
            wa_y = _weighted_avg(refs, "avg_yield", "n")
            wa_d = _weighted_avg(refs, "avg_days", "n")
            if "類似ベッド_平均収量" in out.columns and wa_y is not None:
                out["類似ベッド_平均収量"] = wa_y
            if "類似ベッド_平均日数" in out.columns and wa_d is not None:
                out["類似ベッド_平均日数"] = wa_d

            if "営業調整日数" in out.columns:
                adj = _auto_business_adjust_days(p)
                if adj is not None:
                    out["営業調整日数"] = adj

        # 列順を expected に合わせる
        out = out.reindex(columns=expected)

    return out

# =========================================================
# 複数レコードの一括構築ヘルパー
# =========================================================

def build_feature_frame(payloads: Iterable[Dict[str, Any]], preproc) -> pd.DataFrame:
    """複数レコード版。各 payload を build_feature_row に通して縦に結合。"""
    expected = _expected_columns_from_preproc(preproc)
    rows = []
    for p in payloads:
        rows.append(build_feature_row(p, preproc).iloc[0].to_dict())
    if expected:
        return pd.DataFrame(rows, columns=expected)
    return pd.DataFrame(rows)
