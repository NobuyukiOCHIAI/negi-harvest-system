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
2. `.env.example` を `.env` にコピーし `XGB_API_URL`（`http://tk2-118-59530.vs.sakura.ne.jp/xgbapi/api/`）と `XGB_API_KEY` を設定
3. `db.php` の接続情報を設定
4. `/data_entry/harvest.php` にブラウザでアクセス

## 制約事項

- データベースアクセスには MySQLi を使用し、PDO は使用しないでください。
- 重要なエラーは `api/logging.php` の `log_error` を使用して `/home/love-media/forc_logs` に記録してください。

## テスト手順

### 推論 API `/predict_both`

- ベース URL: `http://tk2-118-59530.vs.sakura.ne.jp/xgbapi/api/`
- すべてのリクエストで `X-Api-Key: <.envで設定したAPIキー>` ヘッダーを付与します。

1. **ヘルスチェック**  
   `GET /health` を送信し、`ok: true` と `feature_order_size`、`model_path_days` などが返ることを確認します。

2. **特徴量メタ情報の取得（任意）**  
   `GET /feature_meta` で `feature_order` を取得すると、送信すべき特徴量キーを事前確認できます。

3. **推論リクエストの送信**  
   - メソッド: `POST`
   - エンドポイント: `/predict_both`
   - ヘッダー: `Content-Type: application/json` と `X-Api-Key`
   - ボディ形式: 
     ```json
     {
       "data": [
         {
           "features": {
             "育苗日数": 21,
             "定植月": 8,
             "グループ_通常": 1,
             "気温_平均": 28.3,
             "気温_最大": 33.1,
             "気温_最小": 24.9,
             "気温_std": 2.1,
             "気温振れ幅_平均": 6.2,
             "気温振れ幅_std": 1.4,
             "類似ベッド_平均収量": 120,
             "類似ベッド_平均日数": 52,
             "前年同時期収量": 110,
             "前年同時期日数": 55,
             "収量差_前年": 10,
             "日数差_前年": -3,
             "営業調整日数": 0
           }
         }
       ]
     }
     ```
     `"気温_平均"`、`"気温_最大"`、`"気温_最小"`、`"気温_std"`、`"気温振れ幅_平均"`、`"気温振れ幅_std"`、`"営業調整日数"` は必須です。

4. **レスポンス確認**  
   `ok: true` と `predictions` 配列に `days`（整数）と `yield`（実数）が含まれること、`request_id` が付与されることを確認します。入力に誤りがある場合は `ok: false` とエラーコードが返るため、メッセージに従って修正してください。

### 特徴量ビルダー単体実行

1. リポジトリルートで PHP CLI を使用し、対象サイクル ID を指定して `build_features_array` を直接呼び出します。
   ```bash
   php -r 'require_once "db.php";
           require_once "lib/build_features.php";
           $cycleId = <CYCLE_ID>;
           list($features, $asof) = build_features_array($link, $cycleId);
           echo json_encode(
               ["cycle_id"=>$cycleId, "asof"=>$asof, "features"=>$features],
               JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
           ), PHP_EOL;'
   ```

2. コマンドの標準出力に `features_cache` と同一形式の JSON が表示されることを確認します。必要に応じて `> dump.json` でファイル出力、または `rebuild_features_for_cycle($link, $cycleId)` に置き換えてキャッシュ更新を行ってください。

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

## Features Cache 再構成フロー（概要）

1. 定植登録（`data_entry/planting.php`）→ 共通ビルダで `features_cache` 生成
2. 収穫登録（`data_entry/harvest.php`）→ `collections` へ記録 → トリガが `sp_update_sales_adjust_days` 実行 → 共通ビルダで `features_cache` 再生成
3. `features_cache.features_json` の "営業調整日数" は `cycles.sales_adjust_days` を反映（集荷前は0）

### DB セットアップ
- `ALTER TABLE cycles ... sales_adjust_days` を適用
- `sp_update_sales_adjust_days` を作成
- `trg_collections_ai` / `trg_collections_au` を作成
- 既存データに対して `CALL sp_update_sales_adjust_days_all();`（必要なら）

### 検証手順
1. `collections` に対象 `cycle_id` の `pickup_date` を1件追加
2. 直前時点までの `predictions` が存在することを確認
3. 収穫登録処理後、`cycles.sales_adjust_days` が更新されたか確認
4. `rebuild_features_for_cycle($link, $cycleId)` により `features_cache` 再生成
5. SQLで `features_json` に "営業調整日数" が入っていることを確認
   ```sql
   SELECT asof,
          LOCATE('"営業調整日数"', features_json) AS pos,
          SUBSTRING(features_json, GREATEST(1, LOCATE('"営業調整日数"', features_json)-20), 120) AS snippet
   FROM features_cache
   WHERE cycle_id = <TARGET_CYCLE_ID>
   ORDER BY id DESC
   LIMIT 5;
   ```

### ロールバック
1. `DROP TRIGGER ...` でトリガを無効化
2. `sp_update_sales_adjust_days` を再作成（または DROP）
3. `ALTER TABLE cycles DROP COLUMN sales_adjust_days;`
4. `planting.php` / `harvest.php` で `rebuild_features_for_cycle` 呼び出しをコメントアウト

## DB接続ポリシー（PDO禁止／mysqliのみ）

本プロジェクトでは PDOは使用しません。mysqli＋db.php の $link を唯一の接続として使用します。
接続は必ず `require_once __DIR__ . '/db.php';` で取得し、$link（mysqli）を関数に引き回す方針です。

### 接続定義（既存）

```php
// db.php
$link = mysqli_connect('mysql470.db.sakura.ne.jp', 'love-media', 'EuvaMLe8');
if (mysqli_connect_errno() > 0) { echo "DB Connection Error"; exit; }
mysqli_select_db($link, 'love-media_dp');
mysqli_set_charset($link, 'utf8');
```

### 特徴量ビルド（共通モジュール）

ファイル：`lib/build_features.php`（mysqli版）

公開関数：

```
rebuild_features_for_cycle(mysqli $link, int $cycleId, ?string $asofDate=null): array
```

返り値：XGBAPIへ送信する features配列

副作用：`features_cache(cycle_id, asof, features_json, hash)` を更新

仕様：`features_json` は `{ "features": {...} }`、`hash = sha256(cycle_id|asof|features_json)`

必須項目："営業調整日数"（`COALESCE(cycles.sales_adjust_days, 0)`）

### 呼び出し例（定植登録：data_entry/planting.php）

```php
require_once __DIR__ . '/../db.php';               // $link を得る
require_once __DIR__ . '/../lib/build_features.php';

$features = rebuild_features_for_cycle($link, $cycleId);
// 以降 $features を XGBAPI に送信
```

### 検証

DB:

- "営業調整日数" キーが `features_json` に存在することを SQL で確認（例コマンドは上記）

コード:

- `git grep` で PDO参照が0件であること

### 将来の拡張

収穫登録（`data_entry/harvest.php`）完了後にも
`rebuild_features_for_cycle($link, $cycleId)` を呼ぶことで、営業調整日数の反映を即時に行える。

