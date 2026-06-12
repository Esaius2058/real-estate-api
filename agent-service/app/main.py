from fastapi import FastAPI, APIRouter, Depends
from fastapi.middleware.cors import CORSMiddleware
from app.core.auth import get_current_user_token
from app.core.config import settings

app = FastAPI(
    title=settings.PROJECT_NAME,
    version=settings.VERSION,
    description="LangGraph orchestration service for the Makao Platform"
)

# CORS Configuration
# Since React talks to Laravel, and Laravel talks to this Python service, 
# we restrict CORS to local network/Laravel sources.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:8000", "http://127.0.0.1:8000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/health")
async def health_check():
    """
    Basic health check endpoint for deployment orchestration.
    """
    return {
        "status": "online",
        "service": settings.PROJECT_NAME,
        "version": settings.VERSION
    }

router = APIRouter(prefix="/agents")

@router.post("/test-laravel-connection")
async def test_connection(token: str = Depends(get_current_user_token)):
    # This is a sample showing how you'd use the client
    client = LaravelClient(token=token)
    data = await client.get("me") # Calls /api/v1/me in Laravel
    return {"status": "authenticated", "user": data}

app.include_router(router)