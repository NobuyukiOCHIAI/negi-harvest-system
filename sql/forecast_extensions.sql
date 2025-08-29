-- Additional tables and views for harvest forecasting
-- Compatible with MySQL 5.5 (no native JSON or CHECK constraints)

CREATE TABLE IF NOT EXISTS calendar_shipments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  week_start_date DATE NOT NULL UNIQUE,
  committed_amount_kg DECIMAL(10,2) NOT NULL,
  source ENUM('gcal','manual') NOT NULL,
  gcal_event_id VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS collections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  pickup_date DATE NOT NULL,
  amount_kg DECIMAL(10,2) NOT NULL,
  client VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (cycle_id, pickup_date),
  FOREIGN KEY (cycle_id) REFERENCES cycles(id)
);

CREATE TABLE IF NOT EXISTS predictions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  model_id VARCHAR(64),
  pred_days DECIMAL(8,3),
  pred_total_kg DECIMAL(10,3),
  postproc_days DECIMAL(8,3),
  postproc_total_kg DECIMAL(10,3),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (cycle_id, created_at),
  FOREIGN KEY (cycle_id) REFERENCES cycles(id)
);

CREATE TABLE IF NOT EXISTS features_cache (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  asof DATE NOT NULL,
  -- MySQL 5.5 lacks a native JSON type; store encoded JSON in TEXT
  features_json TEXT NOT NULL,
  hash CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (cycle_id, asof),
  FOREIGN KEY (cycle_id) REFERENCES cycles(id)
);

CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  type ENUM('shortage','delay','loss_spike','data_missing') NOT NULL,
  payload_json TEXT,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (type, date)
);

-- Forecast views
CREATE OR REPLACE VIEW weekly_harvest_forecast_v AS
SELECT
  DATE_SUB(
    DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY),
    INTERVAL (DAYOFWEEK(DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) - 1) DAY
  ) AS week_start_date,
  SUM(COALESCE(pr.postproc_total_kg, pr.pred_total_kg)) AS forecast_total_kg,
  COUNT(*) AS beds_count
FROM cycles c
JOIN predictions pr
  ON pr.cycle_id = c.id
 AND NOT EXISTS (
       SELECT 1 FROM predictions p2
        WHERE p2.cycle_id = pr.cycle_id
          AND p2.created_at > pr.created_at
     )
WHERE c.harvest_end IS NULL
  AND DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY) >= CURDATE()
GROUP BY week_start_date;

CREATE OR REPLACE VIEW harvest_actual_base_v AS
SELECT
  c.id AS cycle_id,
  c.harvest_end AS final_dt,
  DATE_SUB(c.harvest_end, INTERVAL (DAYOFWEEK(c.harvest_end)-1) DAY) AS week_start_date,
  DATE_SUB(c.harvest_end, INTERVAL (DAYOFMONTH(c.harvest_end)-1) DAY) AS month_start_date,
  COALESCE((SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id = c.id),0) AS actual_total_kg
FROM cycles c
WHERE c.harvest_end IS NOT NULL;

CREATE OR REPLACE VIEW weekly_gap_v AS
SELECT
  f.week_start_date,
  f.forecast_total_kg,
  s.committed_amount_kg,
  f.forecast_total_kg - IFNULL(s.committed_amount_kg,0) AS diff_kg
FROM weekly_harvest_forecast_v f
LEFT JOIN calendar_shipments s ON f.week_start_date = s.week_start_date;

-- Add sales_adjust_days column to cycles
ALTER TABLE cycles
  ADD COLUMN sales_adjust_days INT NULL DEFAULT NULL,
  ADD KEY idx_cycles_sales_adjust_days (sales_adjust_days);

-- Stored procedure for sales adjust days
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_update_sales_adjust_days $$
CREATE PROCEDURE sp_update_sales_adjust_days(IN p_cycle_id INT)
proc: BEGIN
  DECLARE v_pickup DATE;
  DECLARE v_plant  DATE;
  DECLARE v_pred_days DECIMAL(8,3);
  DECLARE v_expected DATE;

  /* 1) 最初の実集荷日 */
  SELECT MIN(pickup_date) INTO v_pickup
  FROM collections
  WHERE cycle_id = p_cycle_id;

  IF v_pickup IS NULL THEN
    LEAVE proc; -- 集荷が無ければ何もしないで終了
  END IF;

  /* 2) 定植日 */
  SELECT plant_date INTO v_plant
  FROM cycles
  WHERE id = p_cycle_id;

  /* 3) 初回集荷"直前"の最新予測（無ければ最新） */
  SELECT p.pred_days
    INTO v_pred_days
  FROM predictions p
  WHERE p.cycle_id = p_cycle_id
    AND p.created_at <= v_pickup
  ORDER BY p.created_at DESC
  LIMIT 1;

  IF v_pred_days IS NULL THEN
    SELECT p.pred_days
      INTO v_pred_days
    FROM predictions p
    WHERE p.cycle_id = p_cycle_id
    ORDER BY p.created_at DESC
    LIMIT 1;
  END IF;

  IF v_pred_days IS NULL THEN
    LEAVE proc;
  END IF;

  SET v_expected = DATE_ADD(v_plant, INTERVAL ROUND(v_pred_days,0) DAY);
  UPDATE cycles SET sales_adjust_days = DATEDIFF(v_pickup, v_expected)
  WHERE id = p_cycle_id;
END$$
DELIMITER ;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_collections_ai $$
CREATE TRIGGER trg_collections_ai
AFTER INSERT ON collections
FOR EACH ROW
BEGIN
  CALL sp_update_sales_adjust_days(NEW.cycle_id);
END $$

DROP TRIGGER IF EXISTS trg_collections_au $$
CREATE TRIGGER trg_collections_au
AFTER UPDATE ON collections
FOR EACH ROW
BEGIN
  CALL sp_update_sales_adjust_days(NEW.cycle_id);
END $$

DROP PROCEDURE IF EXISTS sp_update_sales_adjust_days_all $$
CREATE PROCEDURE sp_update_sales_adjust_days_all()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_cycle_id INT;
  DECLARE cur CURSOR FOR
    SELECT DISTINCT c.id
    FROM cycles c
    JOIN collections col ON col.cycle_id = c.id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_cycle_id;
    IF done = 1 THEN LEAVE read_loop; END IF;
    CALL sp_update_sales_adjust_days(v_cycle_id);
  END LOOP;
  CLOSE cur;
END $$
DELIMITER ;
