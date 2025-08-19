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

## 集計ビュー

- `weekly_harvest_forecast_v`: 未収穫サイクルの最新予測のみを週（日曜起点）で集計。
  - `forecast_total_kg` = SUM(COALESCE(postproc_total_kg, pred_total_kg))
  - `beds_count` = 集計週に属する未収穫サイクル件数
  - 予測行の生成契機：定植登録／収穫登録／weather_daily 更新バッチ（未完了サイクルのみ差分再予測）
- `harvest_actual_base_v`: 収穫終了日ベースの実績のみを集計（週・月の起点は日曜／月初）。予測は含めない。
  - 週次／月次の集計は SELECT 側で GROUP BY week_start_date または GROUP BY month_start_date を使用。
  - 過去週の振り返りは実績ビュー（収穫終了日ベース）を使用すること。

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

## 予測・実績ビュー運用（2025-08-19 更新）

- **weekly_harvest_forecast_v** … 未収穫（部分収穫中含む）× 最新予測のみを週（日曜起点）で集計  
  - 列: `week_start_date`, `forecast_total_kg`, `beds_count`  
  - 期待日が過去でも **未収穫であれば集計対象**（遅延の見落とし防止）  
  - 予測行の生成契機: 定植登録 / 収穫登録 / `weather_daily` 更新バッチ（未完了のみ差分再予測）

- **weekly_gap_v** … 上記予測と `calendar_shipments` を `week_start_date` でJOIN  
  - 列: `week_start_date`, `beds_count`, `forecast_total_kg`, `committed_amount_kg`, `diff_kg`  
  - `SQL SECURITY INVOKER` を使用（DEFINER依存を排除）

- **harvest_actual_base_v** … 収穫終了日ベースの実績明細（週・月の起点は日曜／月初）

### expected_harvest の扱い
- DBには **保存しない**（列追加しない）。必要時は `plant_date + ROUND(pred_days)` を**都度算出**して表示に使用。  
- アプリ側（PHP/Python）で `expected_harvest` をオンザフライ計算するのは可（DBへの永続化はしない）。
