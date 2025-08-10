import os, json, logging, logging.config
from fastapi import FastAPI
from dotenv import load_dotenv

# .env をロード
load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", "config", ".env"))

# ロギング設定
logconf = os.path.join(os.path.dirname(__file__), "..", "config", "logging.json")
if os.path.exists(logconf):
    with open(logconf) as f:
        logging.config.dictConfig(json.load(f))
logger = logging.getLogger("xgbapi")

app = FastAPI(title=os.getenv("API_TITLE", "XGB Predict API"))

from .routes import health, predict
app.include_router(health.router, tags=["meta"])
app.include_router(predict.router, tags=["predict"])
