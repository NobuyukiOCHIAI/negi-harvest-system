#!/usr/bin/env bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
export PYTHONPATH="$SCRIPT_DIR"
if [ ! -f "$SCRIPT_DIR/config/.env" ]; then
  cp "$SCRIPT_DIR/config/.env.example" "$SCRIPT_DIR/config/.env"
fi
exec uvicorn app.main:app --host "${API_HOST:-127.0.0.1}" --port "${API_PORT:-8080}"
