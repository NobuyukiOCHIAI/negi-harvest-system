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
