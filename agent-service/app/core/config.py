from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    PROJECT_NAME: str = "Makao Agent Service"
    VERSION: str = "1.0.0"
    
    # Google Gemini Configuration
    GOOGLE_API_KEY: str
    
    # Laravel Core API Connection
    LARAVEL_API_URL: str = "http://localhost:8000/api/v1"
    
    # Internal Service Authentication 
    # (A shared secret or specific Sanctum token to verify requests coming from Laravel)
    SERVICE_API_KEY: str = "secret-dev-key"

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

settings = Settings()