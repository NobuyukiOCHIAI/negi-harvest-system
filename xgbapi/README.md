# XGB API / XGB API（エックスジービーAPI）

FastAPI application providing XGBoost based predictions.
XGBoost に基づく予測を提供する FastAPI アプリケーションです。

## Endpoints / エンドポイント
- `GET /healthz` – lightweight liveness probe (no auth)  
  軽量な生存確認エンドポイント（認証不要）
- `GET /api/health` – detailed health including model paths and feature order size  
  モデルパスと特徴量数を含む詳細なヘルス情報
- `GET /api/feature_meta` – returns feature order metadata  
  特徴量の順序メタデータを返す
- `POST /api/predict` – predict days only  
  日数のみを予測
- `POST /api/predict_both` – predict days and yield simultaneously  
  日数と収量を同時に予測

## Authentication / 認証
All `/api/*` endpoints require an API key. Set `API_KEY` in `.env` and send it via the `X-Api-Key` header.  
すべての `/api/*` エンドポイントでは API キーが必要です。`.env` に `API_KEY` を設定し、`X-Api-Key` ヘッダで送信してください。

## Environment / 環境変数
Required variables in `.env` / `.env` に必須の変数:
- `MODEL_PATH_DAYS`
- `MODEL_PATH_YIELD`

Optional / 任意:
- `PREPROC_PATH`
- `FEATURE_META_PATH`
- `CORS_ALLOW_ORIGINS`, `CORS_ALLOW_METHODS`, `CORS_ALLOW_HEADERS`

## Feature Metadata / 特徴量メタデータ
`GET /api/feature_meta` returns:  
`GET /api/feature_meta` のレスポンス例:
```
{"ok": true, "feature_order": [...], "feature_order_size": <int>}
```

## Health Check / ヘルスチェック
Use `/healthz` for L7 health probes. For detailed monitoring (model paths, feature count) use `/api/health`.  
`/healthz` は L7 ヘルスチェックに使用します。モデルパスや特徴量数など詳細な監視には `/api/health` を利用してください。
