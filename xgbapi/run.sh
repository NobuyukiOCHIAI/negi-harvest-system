#!/usr/bin/env bash
set -euo pipefail

APP_HOME="/home/centos/xgbapi"
ENV_FILE="$APP_HOME/config/.env"

cd "$APP_HOME"

# .env を環境変数として export（Bashの「自動export」モードを一時的に有効にする）
if [[ -f "$ENV_FILE" ]]; then
  # 行末コメントがあると誤読するので、防御的に削除してから読み込む
  # 例: PREPROC_PATH=...  # コメント   の「# コメント」部分を落とす
  TMP_ENV="$(mktemp)"
  sed 's/[[:space:]]*#.*$//' "$ENV_FILE" | sed '/^[[:space:]]*$/d' > "$TMP_ENV"
  set -a
  source "$TMP_ENV"
  set +a
  rm -f "$TMP_ENV"
else
  echo "[FATAL] .env not found: $ENV_FILE" >&2
  exit 1
fi

# 既定値（.env に無ければデフォルト）
API_HOST="${API_HOST:-127.0.0.1}"
API_PORT="${API_PORT:-8080}"

# ログに現在の設定を出す（トラブル時の切り分け用）
echo "[CONFIG] API_HOST           = $API_HOST"
echo "[CONFIG] API_PORT           = $API_PORT"
echo "[CONFIG] MODEL_DIR          = ${MODEL_DIR:-}"
echo "[CONFIG] MODEL_PATH         = ${MODEL_PATH:-}"
echo "[CONFIG] MODEL_PATH_YIELD   = ${MODEL_PATH_YIELD:-}"
echo "[CONFIG] MODEL_PATH_DAYS    = ${MODEL_PATH_DAYS:-}"
echo "[CONFIG] PREPROC_PATH       = ${PREPROC_PATH:-}"
echo "[CONFIG] FEATURE_META_PATH  = ${FEATURE_META_PATH:-}"

# venv を有効化
if [[ -f "$APP_HOME/.venv/bin/activate" ]]; then
  source "$APP_HOME/.venv/bin/activate"
fi

# Uvicorn 起動
# app/main.py 側で "app" という FastAPI インスタンスが公開されている想定
exec python -m uvicorn app.main:app \
  --host "$API_HOST" \
  --port "$API_PORT" \
  --workers 1
