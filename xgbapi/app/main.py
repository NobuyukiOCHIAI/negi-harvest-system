import os, json, logging, logging.config, sys, traceback
from fastapi import FastAPI, Request
from dotenv import load_dotenv
from starlette.responses import Response

# .env をロード
BASE_DIR = os.path.dirname(__file__)
load_dotenv(dotenv_path=os.path.join(BASE_DIR, "..", "config", ".env"))

# ロギング設定
logconf = os.path.join(BASE_DIR, "..", "config", "logging.json")
if os.path.exists(logconf):
    with open(logconf) as f:
        logging.config.dictConfig(json.load(f))
logger = logging.getLogger("xgbapi")

app = FastAPI(title=os.getenv("API_TITLE", "XGB Predict API"))

# ---- DEBUG Middleware (safe body logging) ----
@app.middleware("http")
async def debug_middleware(request: Request, call_next):
    try:
        # ENV（API_KEYはマスク）
        api_key_env = os.getenv("API_KEY", "")
        logger.debug(
            "ENV: API_TITLE=%s MODEL_PATH=%s API_KEY=%s",
            os.getenv("API_TITLE"),
            os.getenv("MODEL_PATH"),
            "*" * len(api_key_env) if api_key_env else "(not set)",
        )

        # Request logging
        try:
            body_bytes = await request.body()
            body_str = body_bytes.decode(errors="ignore")
            if len(body_str) > 1000:
                body_str = body_str[:1000] + "...(truncated)"
            logger.debug(
                "REQUEST: %s %s\nHeaders: %s\nBody: %s",
                request.method, str(request.url), dict(request.headers), body_str
            )
        except Exception as e:
            logger.warning("Failed to read request body for logging: %s", e)

        # 本体実行
        response = await call_next(request)

        # Response logging（ボディを読み戻して再構築）
        try:
            resp_body = b""
            async for chunk in response.body_iterator:
                resp_body += chunk
            logger.debug(
                "RESPONSE: status=%s, body=%s",
                response.status_code, resp_body.decode(errors="ignore")
            )
            return Response(
                content=resp_body,
                status_code=response.status_code,
                headers=dict(response.headers),
                media_type=response.media_type,
            )
        except Exception as e:
            logger.error("Error reading response body: %s", e)
            return response

    except Exception:
        logger.error("=== Exception in request processing ===", exc_info=True)
        print("=== predict exception ===", file=sys.stderr, flush=True)
        traceback.print_exc()
        raise
# ---- END DEBUG ----

# ルータ登録
from .routes import health, predict  # noqa: E402
app.include_router(health.router, tags=["meta"])
app.include_router(predict.router, tags=["predict"])
