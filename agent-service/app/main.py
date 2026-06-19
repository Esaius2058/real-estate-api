import os
import traceback
from fastapi import FastAPI, APIRouter, Depends, HTTPException
from fastapi.middleware.cors import CORSMiddleware
import httpx
from pydantic import BaseModel, Field
from typing import Optional, Dict, Any, List

# Langchain Imports
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.prompts import ChatPromptTemplate

# Internal Imports
from app.tools.laravel_client import LaravelClient
from app.core.auth import get_current_user_token
from app.api.verify import router as verify_router
from app.api.market import router as marketing_router
from app.core.config import settings
from app.graphs.matching.graph import matching_app
from app.graphs.matching.lead_graph import lead_matching_app

app = FastAPI(
    title=settings.PROJECT_NAME,
    version=settings.VERSION,
    description="LangGraph orchestration service for the Makao Platform"
)

# --- Global LLM Instance ---
# Instantiated once at startup, reused across all endpoint invocations
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash", 
    temperature=0.3,
    api_key=os.getenv("GOOGLE_API_KEY")
)

# CORS Configuration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:8000", "http://127.0.0.1:8000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/health")
async def health_check():
    return {
        "status": "online",
        "service": settings.PROJECT_NAME,
        "version": settings.VERSION
    }


# --- Agents Router ---
agents_router = APIRouter(prefix="/agents")

@agents_router.get("/test-laravel-connection")
async def test_connection(token: str = Depends(get_current_user_token)):
    try:
        client = LaravelClient(token=token)
        data = await client.get("me") 
        return {
            "status": "success", 
            "message": "Bridge is active. Sanctum token verified by Laravel.",
            "laravel_response": data
        }
    except httpx.HTTPStatusError as e:
        raise HTTPException(status_code=e.response.status_code, detail=f"Laravel rejected the request: {e.response.text}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

class MatchRequest(BaseModel):
    property_data: Dict[str, Any]
    agency_id: int

class DraftRequest(BaseModel):
    lead_name: str
    property_title: str
    location: str
    price: int | float | str
    reasoning: str

class LeadMatchRequest(BaseModel):
    lead_data: Dict[str, Any]
    agency_id: int

class FeedbackRequest(BaseModel):
    match_id: str
    lead_id: int
    property_id: int
    action: str # "dismissed" or "accepted"

class RoiForecast(BaseModel):
    estimated_annual_roi_percent: float = Field(description="Total annual return on investment percentage (use realistic, conservative numbers)")
    estimated_rental_yield_percent: float = Field(description="Annual rental yield percentage")
    estimated_appreciation_percent: float = Field(description="Expected annual property appreciation percentage")
    confidence: str = Field(description="Must be strictly: low, medium, or high. Default to low/medium if no exact comps are provided.")
    reasoning: str = Field(description="A blunt explanation of the market dynamics driving this estimate.")
    comparable_basis: str = Field(description="Explicitly state if this is based on provided comps or general macro-market knowledge for the area.")

class RoiForecastRequest(BaseModel):
    property_id: int
    property_title: str
    location: str
    price: float
    property_type: str
    # OPTION 2 FUTURE-PROOFING: Accepts external comps when ready
    comparable_listings: Optional[List[Dict[str, Any]]] = None

@agents_router.post("/match")
async def run_property_match(payload: MatchRequest):
    print(f"Received match request for Property ID: {payload.property_data.get('id')}")
    
    initial_state = {
        "property_data": payload.property_data,
        "agency_id": payload.agency_id,
        "leads": [],
        "matches": [],
        "notifications_created": False,
        "errors": []
    }

    try:
        result = await matching_app.ainvoke(initial_state)
        
        if result.get("errors"):
            print(f"Graph executed with errors: {result['errors']}")
            
        return {
            "status": "success" if result.get("notifications_created") else "completed",
            "matches_found": len(result.get("matches", [])),
            "errors": result.get("errors", [])
        }
        
    except Exception as e:
        print(f"Critical Graph Failure: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@agents_router.post("/match-lead")
async def run_lead_match(payload: LeadMatchRequest):
    print(f"Received match request for Lead ID: {payload.lead_data.get('id')}")
    
    initial_state = {
        "lead_data": payload.lead_data,
        "agency_id": payload.agency_id,
        "properties": [],
        "matches": [],
        "errors": []
    }

    try:
        result = await lead_matching_app.ainvoke(initial_state)
        
        if result.get("errors"):
            print(f"Graph executed with errors: {result['errors']}")
            
        return {
            "status": "success",
            "matches_found": len(result.get("matches", [])),
            "errors": result.get("errors", [])
        }
        
    except Exception as e:
        print(f"Critical Lead Graph Failure: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@agents_router.post("/telemetry/feedback")
async def log_feedback(req: FeedbackRequest):
    # In production, save this to a Postgres database or send it to LangSmith
    print(f"TELEMETRY: Match {req.match_id} was {req.action} for Lead {req.lead_id} and Property {req.property_id}")
    return {"status": "logged"}

@agents_router.post("/roi-forecast", response_model=RoiForecast)
async def forecast_roi(payload: RoiForecastRequest):
    try:
        # 1. Handle Grounding Data (Comps are now fed directly by Laravel)
        if payload.comparable_listings and len(payload.comparable_listings) > 0:
            comps_text = "\n".join([f"- {c.get('title')} ({c.get('status', 'active')}): Ksh {c.get('price', 0):,.2f}" for c in payload.comparable_listings])
            system_context = "Calculate projected returns based STRICTLY on the provided comparable market data. Confidence can be medium/high depending on comp quality."
        else:
            comps_text = "NONE PROVIDED."
            system_context = """Calculate projected returns based on your general macroeconomic knowledge of the Kenyan real estate market for this specific location and property type. 
            Because you lack verified comparable sales, your confidence MUST be 'low' or 'medium' at best. 
            In the 'comparable_basis' field, explicitly state: 'Estimate based on general market patterns for this location and property type; not grounded in verified comparable sales.'"""

        # 2. Setup Structured LLM
        structured_llm = llm.with_structured_output(RoiForecast)
        
        prompt = ChatPromptTemplate.from_messages([
            ("system", f"""You are an elite, highly analytical real estate financial analyst. 
            {system_context}
            Be brutal, realistic, and highly specific in your reasoning. Do not over-promise."""),
            ("human", """Analyze the following property and predict its ROI.
            
            SUBJECT PROPERTY:
            Title: {property_title}
            Type: {property_type}
            Location: {location}
            Asking Price: Ksh {price}
            
            COMPARABLE MARKET DATA:
            {comps_text}""")
        ])
        
        chain = prompt | structured_llm
        
        # 3. Execute inference
        result = await chain.ainvoke({
            "property_title": payload.property_title,
            "property_type": payload.property_type,
            "location": payload.location,
            "price": payload.price,
            "comps_text": comps_text
        })
        
        return result
        
    except Exception as e:
        print("\n=== ROI PREDICTION CRASHED ===")
        print(traceback.format_exc()) # This prints the exact line of code that failed
        print("==============================\n")
        raise HTTPException(status_code=500, detail=f"ROI Forecast failed: {str(e)}")   

@agents_router.post("/draft")
async def draft_proposal(req: DraftRequest):
    try:
        prompt = ChatPromptTemplate.from_messages([
            ("system", "You are an elite, highly professional real estate agent. Your communication style is blunt, direct, and high-value. No fluff, no emojis, no aggressive sales jargon. State the facts, present the value proposition clearly based on the reasoning provided, and close with a direct call to action."),
            ("human", "Draft a short, direct proposal for my client, {lead_name}. \n\nProperty: {property_title} in {location} priced at {price}.\nWhy it's a match: {reasoning}\n\nWrite the pitch.")
        ])
        
        # Use the globally initialized LLM
        chain = prompt | llm
        
        formatted_price = f"Ksh {int(req.price):,}" if isinstance(req.price, (int, float)) or req.price.isdigit() else req.price
        
        result = await chain.ainvoke({
            "lead_name": req.lead_name,
            "property_title": req.property_title,
            "location": req.location,
            "price": formatted_price,
            "reasoning": req.reasoning
        })
        
        return {"proposal": result.content.strip()}
        
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

app.include_router(agents_router)
app.include_router(verify_router)
app.include_router(marketing_router)