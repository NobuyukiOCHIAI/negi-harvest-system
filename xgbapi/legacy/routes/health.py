from fastapi import APIRouter

router = APIRouter()


@router.get("/health")
def health():
    return {"ok": True}


@router.get("/ready")
def ready():
    # ここでモデルロード済み判定などを実装する場合は import して参照
    return {"ok": True}
