DROP PROCEDURE IF EXISTS sp_update_sales_adjust_days;
DELIMITER //
CREATE PROCEDURE sp_update_sales_adjust_days(IN p_cycle_id INT)
BEGIN
  DECLARE v_pickup DATE;
  DECLARE v_plant  DATE;
  DECLARE v_pred_days DECIMAL(8,3);
  DECLARE v_expected DATE;

  SELECT MIN(pickup_date) INTO v_pickup FROM collections WHERE cycle_id = p_cycle_id;
  IF v_pickup IS NULL THEN LEAVE sp_update_sales_adjust_days; END IF;

  SELECT plant_date INTO v_plant FROM cycles WHERE id = p_cycle_id;

  SELECT pred_days INTO v_pred_days
  FROM predictions
  WHERE cycle_id = p_cycle_id AND created_at <= v_pickup
  ORDER BY created_at DESC LIMIT 1;

  IF v_pred_days IS NULL THEN
    SELECT pred_days INTO v_pred_days
    FROM predictions WHERE cycle_id = p_cycle_id
    ORDER BY created_at DESC LIMIT 1;
  END IF;
  IF v_pred_days IS NULL THEN LEAVE sp_update_sales_adjust_days; END IF;

  SET v_expected = DATE_ADD(v_plant, INTERVAL ROUND(v_pred_days) DAY);

  -- 列がある環境のみ有効化
  -- UPDATE cycles SET sales_adjust_days = DATEDIFF(v_pickup, v_expected) WHERE id = p_cycle_id;
END //
DELIMITER ;
