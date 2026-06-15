import os
from dotenv import load_dotenv
from pydantic_settings import BaseSettings, SettingsConfigDict

load_dotenv()

class Settings(BaseSettings):
    PROJECT_NAME: str = "Makao Agent Service"
    VERSION: str = "1.0.0"
    
    # Google Gemini Configuration
    GOOGLE_API_KEY: str
    
    # Laravel Core API Connection
    LARAVEL_API_URL: str
    
    # Internal Service Authentication 
    #SERVICE_API_KEY: str = "secret-dev-key"

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

# This is the 'settings' object being imported in other files!
settings = Settings()