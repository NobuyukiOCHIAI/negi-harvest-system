# xgbapi — FastAPI XGBoost Predict API

## Overview
- FastAPI-based inference API exposing endpoints under `/api`.
  FastAPI ベースの推論API。`/api` 配下にエンドポイントを提供。
- Supports simultaneous prediction of days and yield.
  days / yield の **同時推論**に対応。
- Model, preprocessing, and feature order are specified via environment variables.
  モデル・前処理・特徴量順は環境変数で指定。

## Endpoints
- `GET /healthz` – liveness check (lightweight, no auth)
  プロセス生存確認（軽量 / 認証不要）
- `GET /api/health` – detailed health (model load status, feature_order_size, etc.)
  詳細健全性（モデルロード状況・feature_order_size 等）
- `GET /api/feature_meta` – resolved `feature_order` information
  解決済みの `feature_order` を返却
- `POST /api/predict_both` – predict days and yield simultaneously (batch supported)
  days & yield を同時推論（バッチ対応）
- `POST /api/predict` – legacy compatibility
  互換用（必要に応じて記述）

## Auth
- Header `X-Api-Key: <token>` is required.
  ヘッダー `X-Api-Key: <token>` を必須。
- Missing or mismatched key returns `401` (error code `300`).
  未設定・不一致の場合は `401`（エラーコード `300`）。

## Environment (.env)
See `xgbapi/config/.env.example`. `.env` must never be committed.
`xgbapi/config/.env.example` を参照。`.env` は**絶対にコミットしない**。

```dotenv
API_HOST=127.0.0.1
API_PORT=8080
API_KEY=your_api_key_here

MODEL_PATH_DAYS=/abs/path/to/model_days.pkl
MODEL_PATH_YIELD=/abs/path/to/model_yield.pkl
PREPROC_PATH=/abs/path/to/preproc.pkl
FEATURE_META_PATH=/abs/path/to/feature_meta.json

CORS_ALLOW_ORIGINS=http://localhost:3000
CORS_ALLOW_METHODS=GET,POST,OPTIONS
CORS_ALLOW_HEADERS=Content-Type,Accept,X-Api-Key
```

### Feature Order Priority / 特徴量決定優先順位
1. `FEATURE_META_PATH` (JSON: feature_order, etc.)
   `FEATURE_META_PATH`（JSON: feature_order など）
2. `PREPROC_PATH` (estimated via `feature_shim`)
   `PREPROC_PATH`（feature_shim で推定）
3. Model-derived (days → yield)
   モデル由来（days → yield）

### Feature Metadata Example / 特徴量メタデータ例
`GET /api/feature_meta` returns:

```json
{"ok": true, "feature_order": [...], "feature_order_size": <int>}
```

`GET /api/feature_meta` は上記の JSON を返します。


### Error Format (Unified) / エラー返却形式（統一）
All errors return JSON:
全エラーは以下の JSON 形式で返却：

```
{ "ok": false, "error": { "code": <int>, "message": "<str>" }, "request_id": "<str>" }
```

Codes: prediction failure 100, feature mismatch 200, invalid API key 300
コード例：予測失敗: 100 / 特徴量不一致: 200 / APIキー不正: 300

### I/O Examples / 入出力例
**POST /api/predict_both — Request / リクエスト例**

```
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

**POST /api/predict_both — Response / レスポンス例**

```
{
  "ok": true,
  "model_path_days": "/path/to/model_days.pkl",
  "model_path_yield": "/path/to/model_yield.pkl",
  "request_id": "20250814101334133618",
  "predictions": [
    { "days": 52.31, "yield": 149.64 }
  ]
}
```

Note: JSON uses key `"yield"` (Pydantic uses `yield_ = Field(alias="yield")`).
備考: JSONでは "yield" キーで返します（Pydantic側は `yield_ = Field(alias="yield")`）。

### CORS
- Values are read from `.env` (`CORS_ALLOW_*`).
  `.env` から `CORS_ALLOW_*` を読み込んで適用。
- Default allowed headers include `X-Api-Key`, `Content-Type`, `Accept`.
  既定の許可ヘッダーに `X-Api-Key`, `Content-Type`, `Accept` を含む。

### Logging
- `config/logging.json` loaded at startup via `dictConfig`.
  `config/logging.json` を startup で `dictConfig` 適用。
- Handlers and `propagate` configured for `uvicorn.access` and `uvicorn.error`.
  `uvicorn.access` / `uvicorn.error` のハンドラ整合済み。

### Health Checks
- Use `/healthz` for L7 health.
  L7ヘルスは軽量な `/healthz` 推奨。
- Use `/api/health` for detailed monitoring.
  詳細監視は `/api/health`。

### Systemd (Deploy)
- Start with `xgbapi.service`. EnvironmentFile loads `.env`.
  `xgbapi.service` から起動。`EnvironmentFile` で `.env` を読み込み。
- Unneeded lines removed (e.g., `fayenp-codex/...`).
  不要行は削除済み（fayenp-codex/... など）。

### Requirements
- Pinned versions such as `scipy==1.10.1`; see `requirements.txt`.
  固定バージョン（`scipy==1.10.1` など）。`requirements.txt` を参照。

### Legacy
Unused legacy schemas are moved under `legacy/` and are not referenced at runtime.
未使用の旧スキーマは `legacy/` に退避（実行時は未参照）。

#### legacy/
This directory stores past implementations for backup; current code does not use them.
このディレクトリは過去の実装を保管する退避領域です。現行コードは参照していません。
- `app/schemas/_schemas_unused.py` – legacy schema collection / 旧スキーマ集約
- `app/schemas/_predict_unused.py` – legacy Predict schemas / 旧 Predict 系スキーマ
As of 2025-08, active runtime uses `app/schemas_predict.py`.
2025-08時点の現行稼働は `app/schemas_predict.py` を使用します。
