-- 実収穫量モニター: total / kg_per_* は GOMI 込み（パフォーマンス指標）
-- gomi_kg は内訳。在庫・出荷可能集計とは別。

CREATE OR REPLACE VIEW harvest_actual_monthly_v AS
SELECT
  e.month_start_date,
  e.harvest_year,
  e.harvest_month,
  COUNT(0) AS event_count,
  COUNT(DISTINCT e.cycle_id) AS cycle_count,
  COUNT(DISTINCT e.bed_id) AS bed_count,
  ROUND(SUM(e.harvest_kg), 1) AS total_kg,
  ROUND(SUM(CASE WHEN e.is_gomi = 1 THEN e.harvest_kg ELSE 0 END), 1) AS gomi_kg,
  ROUND(SUM(e.harvest_kg) / NULLIF(COUNT(DISTINCT e.cycle_id), 0), 1) AS kg_per_cycle,
  ROUND(SUM(e.harvest_kg) / 4.0, 1) AS kg_per_week_of_month
FROM harvest_actual_events_v e
GROUP BY e.month_start_date, e.harvest_year, e.harvest_month;

CREATE OR REPLACE VIEW harvest_actual_weekly_v AS
SELECT
  e.week_start_date,
  e.harvest_year,
  COUNT(0) AS event_count,
  COUNT(DISTINCT e.cycle_id) AS cycle_count,
  COUNT(DISTINCT e.bed_id) AS bed_count,
  ROUND(SUM(e.harvest_kg), 1) AS total_kg,
  ROUND(SUM(CASE WHEN e.is_gomi = 1 THEN e.harvest_kg ELSE 0 END), 1) AS gomi_kg,
  ROUND(SUM(e.harvest_kg) / NULLIF(COUNT(DISTINCT e.cycle_id), 0), 1) AS kg_per_cycle
FROM harvest_actual_events_v e
GROUP BY e.week_start_date, e.harvest_year;
