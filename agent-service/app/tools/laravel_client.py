import httpx
from app.core.config import settings

class LaravelClient:
    def __init__(self, token: str = None):
        self.base_url = settings.LARAVEL_API_URL
        self.token = token

    async def get(self, endpoint: str):
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.token}" if self.token else ""
        }
        
        async with httpx.AsyncClient() as client:
            response = await client.get(f"{self.base_url}/{endpoint}", headers=headers)
            response.raise_for_status()
            return response.json()

    async def post(self, endpoint: str, data: dict):
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.token}" if self.token else ""
        }
        
        async with httpx.AsyncClient() as client:
            response = await client.post(f"{self.base_url}/{endpoint}", json=data, headers=headers)
            response.raise_for_status()
            return response.json()