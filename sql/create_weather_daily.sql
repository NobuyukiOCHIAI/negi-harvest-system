CREATE TABLE IF NOT EXISTS weather_daily (
    date DATE PRIMARY KEY,
    temp_avg FLOAT,
    temp_max FLOAT,
    temp_min FLOAT,
    variation FLOAT
);
