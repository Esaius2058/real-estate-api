from fastapi import FastAPI, APIRouter, Depends
from fastapi.middleware.cors import CORSMiddleware
from app.tools.laravel_client import LaravelClient
from app.core.auth import get_current_user_token
from app.api.verify import router as verify_router
from app.core.config import settings
import httpx

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


# --- Agents Router ---
agents_router = APIRouter(prefix="/agents")

@agents_router.get("/test-laravel-connection")
async def test_connection(token: str = Depends(get_current_user_token)):
    """
    Tests the bridge between FastAPI and Laravel by making an authenticated
    request back to the Laravel /api/v1/me endpoint.
    """
    try:
        client = LaravelClient(token=token)
        # Attempt to fetch the currently authenticated user from Laravel
        data = await client.get("me") 
        return {
            "status": "success", 
            "message": "Bridge is active. Sanctum token verified by Laravel.",
            "laravel_response": data
        }
    except httpx.HTTPStatusError as e:
        # If Laravel rejects the token (e.g., 401 Unauthorized)
        raise HTTPException(status_code=e.response.status_code, detail=f"Laravel rejected the request: {e.response.text}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

app.include_router(agents_router)

app.include_router(verify_router)