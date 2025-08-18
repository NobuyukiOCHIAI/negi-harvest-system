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

## 予測フロー

- 温度は実測のみで将来値は使用しません。
  - `asof = MIN(今日, weather_daily.MAX(date))`
  - `asof >= plant_date` のときは `[plant_date, asof]`
  - `asof < plant_date` のときは `[asof-6, asof]` の直近7日で集計
- 近傍参照は収穫完了日基準で `[今日-5, 今日]` → `[今日-10, 今日]` → `[今日-14, 今日]` と拡大し、同一グループ優先で K=1。
  - 見つからなければ全体平均
- YOY は前年±5日のデータを同一ベッド＞同一グループ＞全体の順で検索。
- 期待収穫日は DB に保存せず、`expected = plant_date + ROUND(pred_days)` を都度算出します。

## 障害時の動作

- `weather_daily` が空、または asof が取得不能の場合は予測を停止し `alerts(data_missing)` を記録します。
- 近傍が見つからない場合は全体平均で埋めて推論を継続します。
- API 呼び出しが失敗した場合は直近の `predictions` をフォールバックとして UI に「前回値」を表示します。

## 運用ログ

- エラーは `api/logging.php` の `log_error` を使用して出力します。
- ログにはステージ名（`$__stage`）を含め、落ち所を迅速に特定できるようにします。

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
