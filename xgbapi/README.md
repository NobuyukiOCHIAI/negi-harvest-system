# XGB API

FastAPI application providing XGBoost based predictions.

## Endpoints
- `GET /healthz` – lightweight liveness probe (no auth)
- `GET /api/health` – detailed health including model paths and feature order size
- `GET /api/feature_meta` – returns feature order metadata
- `POST /api/predict` – predict days only
- `POST /api/predict_both` – predict days and yield simultaneously

## Authentication
All `/api/*` endpoints require an API key. Set `API_KEY` in `.env` and send it
via the `X-Api-Key` header.

## Environment
Required variables in `.env`:
- `MODEL_PATH_DAYS`
- `MODEL_PATH_YIELD`

Optional:
- `PREPROC_PATH`
- `FEATURE_META_PATH`
- `CORS_ALLOW_ORIGINS`, `CORS_ALLOW_METHODS`, `CORS_ALLOW_HEADERS`

## Feature Metadata
`GET /api/feature_meta` returns:
```
{"ok": true, "feature_order": [...], "feature_order_size": <int>}
```

## Health Check
Use `/healthz` for L7 health probes. For detailed monitoring (model paths,
feature count) use `/api/health`.
