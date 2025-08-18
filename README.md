# Negi Harvest System

水耕栽培小ねぎの栽培管理・収穫登録・予測システム。

## 必要環境
- PHP 7.4+
- MySQL 5.5 以上 (JSON 型が無いため TEXT で保存しアプリ側でバリデーション)

## セットアップ手順
1. `sql/schema.sql`、`sql/create_weather_daily.sql`、`sql/insert_weather_daily.sql`、`sql/sample_data.sql`、`sql/forecast_extensions.sql` をインポート
   - MySQL 5.5 では `JSON_OBJECT` などの JSON 関数が使えないため、JSON データは PHP 側で組み立ててください
   - `features_cache.features_json` や `alerts.payload_json` は TEXT 型なので、INSERT/UPDATE 前に `api/json_utils.php` の `encode_json` / `decode_json` で構造をチェックしてください
   - `insert_weather_daily.sql` はリポジトリ内の `ondo.xlsx` から気温データを登録します
2. `.env.example` を `.env` にコピーし `XGB_API_URL` と `XGB_API_KEY` を設定
3. `db.php` の接続情報を設定
4. `/data_entry/harvest.php` にブラウザでアクセス

## 制約事項

- データベースアクセスには MySQLi を使用し、PDO は使用しないでください。
- 重要なエラーは `api/logging.php` の `log_error` を使用して `/home/love-media/forc_logs` に記録してください。

## ディレクトリ構成
```
negi-harvest-system/
├── README.md
├── .gitignore
├── .env.example
├── db.php
├── sql/
│   ├── schema.sql
│   ├── create_weather_daily.sql
│   ├── insert_weather_daily.sql
│   ├── forecast_extensions.sql
│   └── sample_data.sql
└── data_entry/
    ├── harvest.php
    ├── get_cycle_history.php
    ├── get_beds.php
    └── get_loss_types.php
```
