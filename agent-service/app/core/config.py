from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    PROJECT_NAME: str = "Makao Agent Service"
    VERSION: str = "1.0.0"
    
    # Google Gemini Configuration
    GOOGLE_API_KEY: str
    
    # Laravel Core API Connection
    #LARAVEL_API_URL: str = "http://localhost:8000/api/v1"
    
    # Internal Service Authentication 
    #SERVICE_API_KEY: str = "secret-dev-key"

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

# This is the 'settings' object being imported in other files!
settings = Settings()