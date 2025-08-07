CREATE TABLE beds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  group_type VARCHAR(20) NOT NULL
);

CREATE TABLE cycles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bed_id INT NOT NULL,
  sow_date DATE,
  plant_date DATE,
  harvest_start DATE,
  harvest_end DATE,
  -- Extended feature columns
  base_growth_days INT NULL COMMENT '基準生育日数（季節別）',
  expected_harvest DATE NULL COMMENT '予定収穫日',
  sales_adjust_days INT NULL COMMENT '営業調整日数（実-予定）',
  similar_bed_avg_yield DECIMAL(8,2) NULL COMMENT '類似ベッド平均収量',
  similar_bed_avg_days DECIMAL(8,2) NULL COMMENT '類似ベッド平均日数',
  prev_year_yield DECIMAL(8,2) NULL COMMENT '前年同時期収量',
  prev_year_days DECIMAL(8,2) NULL COMMENT '前年同時期日数',
  yield_diff_prev DECIMAL(8,2) NULL COMMENT '収量差_前年',
  days_diff_prev DECIMAL(8,2) NULL COMMENT '日数差_前年',
  temp_avg DECIMAL(5,2) NULL COMMENT '平均気温',
  temp_max DECIMAL(5,2) NULL COMMENT '最高気温',
  temp_min DECIMAL(5,2) NULL COMMENT '最低気温',
  temp_std DECIMAL(5,2) NULL COMMENT '気温標準偏差',
  temp_range_avg DECIMAL(5,2) NULL COMMENT '平均日較差',
  temp_range_std DECIMAL(5,2) NULL COMMENT '日較差標準偏差',
  season_flag TINYINT NULL COMMENT '季節フラグ（1=夏, 2=冬, 3=中間期）',
  FOREIGN KEY (bed_id) REFERENCES beds(id)
);

CREATE TABLE harvests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  harvest_date DATE,
  harvest_kg DECIMAL(10,2),
  loss_type_id INT,
  user_id INT,
  harvest_ratio DECIMAL(4,2),
  FOREIGN KEY (cycle_id) REFERENCES cycles(id)
);

CREATE TABLE loss_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL
);

CREATE TABLE weather_daily (
  date DATE PRIMARY KEY,
  avg_temp FLOAT,
  max_temp FLOAT,
  min_temp FLOAT,
  variation FLOAT
);
