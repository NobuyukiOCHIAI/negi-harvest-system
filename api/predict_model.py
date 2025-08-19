#!/usr/bin/env python3
import argparse
import json
import os
from datetime import datetime, date, timedelta

import pymysql
import pandas as pd
import pickle

DB_CONFIG = {
    'host': 'localhost',
    'user': 'username',
    'password': 'password',
    'db': 'database_name',
    'charset': 'utf8',
    'cursorclass': pymysql.cursors.DictCursor,
}

# 修正: 基準生育日数を実績平均化
DEFAULT_BASE_GROWTH_DAYS = {1: 50, 2: 120, 3: 80}

def determine_season_flag(plant_date):
    month = plant_date.month
    if month in (7, 8, 9):
        return 1  # 修正: 季節区分をモデル仕様に合わせた
    elif month in (11, 12, 1, 2, 3):
        return 2  # 修正: 季節区分をモデル仕様に合わせた
    else:
        return 3  # 修正: 季節区分をモデル仕様に合わせた

def compute_and_update_features(cycle_id):
    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT c.*, b.group_type FROM cycles c JOIN beds b ON c.bed_id=b.id WHERE c.id=%s""",
                (cycle_id,),
            )
            cycle = cur.fetchone()
            if not cycle:
                return None, "Cycle not found"
            plant_date = cycle["plant_date"]
            harvest_start = cycle["harvest_start"]
            group_type = cycle["group_type"]
            season_flag = determine_season_flag(plant_date)

            cur.execute(
                """
                SELECT AVG(DATEDIFF(harvest_start, plant_date)) AS avg_days
                FROM cycles
                WHERE season_flag=%s AND harvest_start IS NOT NULL
                """,
                (season_flag,),
            )
            row = cur.fetchone()
            base_growth_days = (
                row["avg_days"]
                if row["avg_days"] is not None
                else DEFAULT_BASE_GROWTH_DAYS[season_flag]
            )  # 修正: 基準生育日数を実績平均化

            expected_harvest = (
                plant_date + timedelta(days=base_growth_days)
                if plant_date and base_growth_days
                else None
            )
            sales_adjust_days = (
                (harvest_start - expected_harvest).days
                if harvest_start and expected_harvest
                else None
            )

            # Similar bed averages
            start_range = plant_date - timedelta(days=7)
            end_range = plant_date + timedelta(days=7)
            cur.execute(
                """
                SELECT AVG(h.total_yield) AS avg_yield,
                       AVG(DATEDIFF(c.harvest_start, c.plant_date)) AS avg_days
                FROM cycles c
                JOIN beds b ON c.bed_id = b.id
                LEFT JOIN (
                    SELECT cycle_id, SUM(harvest_kg) AS total_yield
                    FROM harvests
                    GROUP BY cycle_id
                ) h ON c.id = h.cycle_id
                WHERE b.group_type=%s AND c.id<>%s AND c.plant_date BETWEEN %s AND %s
                """,
                (group_type, cycle_id, start_range, end_range),
            )
            sim = cur.fetchone()
            similar_yield = sim["avg_yield"]
            similar_days = sim["avg_days"]

            # Previous year
            prev_start = plant_date - timedelta(days=365 + 5)
            prev_end = plant_date - timedelta(days=365 - 5)
            cur.execute(
                """
                SELECT AVG(h.total_yield) AS avg_yield,
                       AVG(DATEDIFF(c.harvest_start, c.plant_date)) AS avg_days
                FROM cycles c
                JOIN beds b ON c.bed_id = b.id
                LEFT JOIN (
                    SELECT cycle_id, SUM(harvest_kg) AS total_yield
                    FROM harvests
                    GROUP BY cycle_id
                ) h ON c.id = h.cycle_id
                WHERE b.group_type=%s AND c.plant_date BETWEEN %s AND %s
                """,
                (group_type, prev_start, prev_end),
            )
            prev = cur.fetchone()
            prev_yield = prev["avg_yield"]
            prev_days = prev["avg_days"]

            yield_diff_prev = (
                similar_yield - prev_yield
                if similar_yield is not None and prev_yield is not None
                else None
            )
            days_diff_prev = (
                similar_days - prev_days
                if similar_days is not None and prev_days is not None
                else None
            )

            temp_end = harvest_start or expected_harvest
            if plant_date and temp_end:
                cur.execute(
                    """
                    SELECT AVG(temp_avg) AS avg_t,
                           MAX(temp_max) AS max_t,
                           MIN(temp_min) AS min_t,
                           STDDEV(temp_avg) AS std_t,
                           AVG(temp_max - temp_min) AS range_avg,
                           STDDEV(temp_max - temp_min) AS range_std
                    FROM weather_daily
                    WHERE date BETWEEN %s AND %s
                    """,
                    (plant_date, temp_end),
                )
                temps = cur.fetchone()
            else:
                temps = {
                    "avg_t": None,
                    "max_t": None,
                    "min_t": None,
                    "std_t": None,
                    "range_avg": None,
                    "range_std": None,
                }

            cur.execute(
                """
                UPDATE cycles SET
                    base_growth_days=%s,
                    sales_adjust_days=%s,
                    similar_bed_avg_yield=%s,
                    similar_bed_avg_days=%s,
                    prev_year_yield=%s,
                    prev_year_days=%s,
                    yield_diff_prev=%s,
                    days_diff_prev=%s,
                    temp_avg=%s,
                    temp_max=%s,
                    temp_min=%s,
                    temp_std=%s,
                    temp_range_avg=%s,
                    temp_range_std=%s,
                    season_flag=%s
                WHERE id=%s
                """,
                (
                    base_growth_days,
                    sales_adjust_days,
                    similar_yield,
                    similar_days,
                    prev_yield,
                    prev_days,
                    yield_diff_prev,
                    days_diff_prev,
                    temps["avg_t"],
                    temps["max_t"],
                    temps["min_t"],
                    temps["std_t"],
                    temps["range_avg"],
                    temps["range_std"],
                    season_flag,
                    cycle_id,
                ),
            )
            conn.commit()

            cur.execute("SELECT * FROM cycles WHERE id=%s", (cycle_id,))
            features = cur.fetchone()
            return features, expected_harvest, None
    finally:
        conn.close()


def predict(features, apply_partial=False, partial_yield=None, partial_ratio=None):
    model_days = pickle.load(open("model_days_integrated.pkl", "rb"))
    model_yield = pickle.load(open("model_yield_integrated.pkl", "rb"))
    cols = [
        "base_growth_days",
        "similar_bed_avg_yield",
        "similar_bed_avg_days",
        "prev_year_yield",
        "prev_year_days",
        "yield_diff_prev",
        "days_diff_prev",
        "temp_avg",
        "temp_max",
        "temp_min",
        "temp_std",
        "temp_range_avg",
        "temp_range_std",
        "season_flag",
    ]
    df = pd.DataFrame([{c: features.get(c) for c in cols}])
    pred_days = int(round(model_days.predict(df)[0]))
    pred_yield = float(model_yield.predict(df)[0])
    if (
        apply_partial
        and partial_yield is not None
        and partial_ratio not in (None, 0)
    ):
        alpha = 0.5  # 修正: 部分収穫補正を加重平均方式に変更
        partial_est_total = partial_yield / partial_ratio
        pred_corrected = pred_yield * alpha + partial_est_total * (1 - alpha)
    else:
        pred_corrected = pred_yield
    return pred_days, pred_yield, pred_corrected


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--cycle_id", type=int, required=True)
    parser.add_argument("--apply_partial", action="store_true")
    parser.add_argument("--partial_yield", type=float)
    parser.add_argument("--partial_ratio", type=float)
    args = parser.parse_args()

    features, expected_harvest, err = compute_and_update_features(args.cycle_id)
    if err:
        print(json.dumps({"status": "error", "message": err}, ensure_ascii=False))
        return

    pred_days, pred_yield, pred_corr = predict(
        features,
        apply_partial=args.apply_partial,
        partial_yield=args.partial_yield,
        partial_ratio=args.partial_ratio,
    )

    # 予測後にオンザフライ算出（DB保存はしない）
    if not expected_harvest:
        plant = features.get("plant_date")
        if isinstance(plant, str):
            try:
                plant = datetime.strptime(plant, "%Y-%m-%d").date()
            except Exception:
                plant = None
        if isinstance(plant, date):
            expected_harvest = plant + timedelta(days=int(pred_days))
        else:
            expected_harvest = None

    result = {
        "status": "success",
        "cycle_id": args.cycle_id,
        "expected_harvest_date": expected_harvest.strftime("%Y-%m-%d")
        if expected_harvest
        else None,
        "predicted_growth_days": pred_days,
        "predicted_yield": round(pred_yield, 1),
        "predicted_yield_corrected": round(pred_corr, 1),
        "season_flag": features["season_flag"],
        "sales_adjust_days": features["sales_adjust_days"],
    }
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
