from fastapi import Request, HTTPException, Depends, Security
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials

security = HTTPBearer()

async def get_current_user_token(credentials: HTTPAuthorizationCredentials = Security(security)):
    """
    Dependency to extract and validate the Bearer token provided 
    by the Laravel request.
    """
    token = credentials.credentials
    if not token:
        raise HTTPException(status_code=401, detail="Missing authentication token")
    return token