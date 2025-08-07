CREATE TABLE IF NOT EXISTS weather_daily (
    date DATE PRIMARY KEY,
    avg_temp FLOAT,
    max_temp FLOAT,
    min_temp FLOAT,
    variation FLOAT
);
