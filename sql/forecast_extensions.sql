-- Additional tables and views for harvest forecasting

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
  features_json JSON NOT NULL,
  hash CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (cycle_id, asof),
  FOREIGN KEY (cycle_id) REFERENCES cycles(id)
);

CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  type ENUM('shortage','delay','loss_spike','data_missing') NOT NULL,
  payload_json JSON,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (type, date)
);

-- Forecast views
CREATE OR REPLACE VIEW weekly_harvest_forecast_v AS
SELECT
  DATE_SUB(c.harvest_end, INTERVAL (DAYOFWEEK(c.harvest_end)-1) DAY) AS week_start_date,
  SUM(COALESCE(p.postproc_total_kg, p.pred_total_kg)) AS forecast_total_kg
FROM cycles c
JOIN (
  SELECT pr1.*
  FROM predictions pr1
  JOIN (
    SELECT cycle_id, MAX(created_at) AS created_at
    FROM predictions
    GROUP BY cycle_id
  ) pr2 ON pr1.cycle_id = pr2.cycle_id AND pr1.created_at = pr2.created_at
) p ON c.id = p.cycle_id
GROUP BY week_start_date;

CREATE OR REPLACE VIEW weekly_gap_v AS
SELECT
  f.week_start_date,
  f.forecast_total_kg,
  s.committed_amount_kg,
  f.forecast_total_kg - IFNULL(s.committed_amount_kg,0) AS diff_kg
FROM weekly_harvest_forecast_v f
LEFT JOIN calendar_shipments s ON f.week_start_date = s.week_start_date;

-- Stored procedure example for sales adjust days
DELIMITER $$
CREATE PROCEDURE sp_update_sales_adjust_days(IN p_cycle_id INT)
BEGIN
  DECLARE exp DATE;
  DECLARE actual DATE;
  SELECT expected_harvest INTO exp FROM cycles WHERE id = p_cycle_id;
  SELECT MIN(pickup_date) INTO actual FROM collections WHERE cycle_id = p_cycle_id;
  IF exp IS NOT NULL AND actual IS NOT NULL THEN
    UPDATE cycles SET sales_adjust_days = DATEDIFF(actual, exp) WHERE id = p_cycle_id;
  END IF;
END$$
DELIMITER ;
