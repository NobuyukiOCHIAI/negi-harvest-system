CREATE OR REPLACE VIEW weekly_harvest_forecast_v AS
WITH latest_pred AS (
  SELECT p.*
  FROM predictions p
  JOIN (
    SELECT cycle_id, MAX(created_at) AS last_ts
    FROM predictions GROUP BY cycle_id
  ) x ON x.cycle_id = p.cycle_id AND x.last_ts = p.created_at
),
final_date AS (
  SELECT
    c.id AS cycle_id,
    COALESCE(c.harvest_end, DATE_ADD(c.plant_date, INTERVAL ROUND(lp.pred_days) DAY)) AS final_dt
  FROM cycles c
  LEFT JOIN latest_pred lp ON lp.cycle_id = c.id
)
SELECT
  DATE_SUB(final_dt, INTERVAL (DAYOFWEEK(final_dt)-1) DAY) AS week_start_date,
  SUM(COALESCE(lp.postproc_total_kg, lp.pred_total_kg)) AS forecast_total_kg,
  COUNT(*) AS beds_count
FROM final_date d
LEFT JOIN latest_pred lp ON lp.cycle_id = d.cycle_id
GROUP BY week_start_date;
