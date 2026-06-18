import json, os
from typing import Dict, Any, List
from pydantic import BaseModel, Field
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.prompts import ChatPromptTemplate
from .state import MatcherState
import httpx
# Assuming you have a settings config and a wrapper for Laravel API calls
from app.core.config import settings
from app.tools.laravel_client import LaravelClient 

# Load M2M settings directly from environment
LARAVEL_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000")
M2M_TOKEN = os.getenv("LARAVEL_M2M_TOKEN", "m2m_test_token_123")
HEADERS = {"Authorization": f"Bearer {M2M_TOKEN}", "Accept": "application/json"}


# 1. Define the LLM Structured Output Schema
class LeadMatch(BaseModel):
    lead_id: int
    match_score: int = Field(description="Score from 0 to 100 indicating how well the property fits the lead's requirements.")
    reasoning: str = Field(description="A concise, 1-2 sentence explanation of why this lead matches the property.")

class MatchingResponse(BaseModel):
    matches: List[LeadMatch]

# 2. Initialize Gemini Flash
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    google_api_key=settings.GOOGLE_API_KEY,
    temperature=0.1 # Low temperature for analytical consistency
)
structured_llm = llm.with_structured_output(MatchingResponse)

# Graph Nodes
async def fetch_leads(state: MatcherState) -> Dict[str, Any]:
    """Fetch active leads for the specific agency from Laravel via M2M tunnel."""
    agency_id = state.get('agency_id')
    
    url = f"{LARAVEL_URL}/api/v1/internal/ai/agencies/{agency_id}/leads"
    print(f"DEBUG - Requesting URL: {url}")
    
    async with httpx.AsyncClient() as client:
        response = await client.get(url, headers=HEADERS)
        
    if response.status_code != 200:
        return {"errors": [f"Failed to fetch leads: {response.text}"]}
        
    # Safely handle the JSON payload
    try:
        json_data = response.json()
        leads = json_data.get("data", []) if isinstance(json_data, dict) else json_data
    except Exception as e:
        print(f"DEBUG - JSON Parse Error: {e}")
        leads = []
        
    return {"leads": leads}


async def match_reasoning(state: MatcherState) -> Dict[str, Any]:
    """Pass property details and lead_requirements to Gemini Flash for scoring."""
    if not state.get("leads"):
        return {"matches": [], "errors": ["No active leads found."]}

    property_data = state["property_data"]
    leads = state["leads"]

    prompt = ChatPromptTemplate.from_messages([
        ("system", """You are an elite real estate matchmaking system built for Makao Real Estate Platform. 
        Analyze the newly listed property and the provided list of buyer requirements. 
        Return a scored list of leads based on strict data fit (budget, location, amenities, sizing).
        Be highly critical. If a lead does not fit, give them a low score."""),
        ("human", "Property Details:\n{property_json}\n\nLeads Requirements:\n{leads_json}")
    ])

    chain = prompt | structured_llm

    try:
        # Strip heavy payload data to save context window and focus on constraints
        slim_leads = [{"id": l["id"], "reqs": l.get("lead_requirements", {})} for l in leads]
        
        result = await chain.ainvoke({
            "property_json": json.dumps(property_data),
            "leads_json": json.dumps(slim_leads)
        })
        
        # Convert Pydantic models back to dictionaries for LangGraph state compatibility
        all_matches = [match.model_dump() for match in result.matches]
        
        # Filter out weak matches (e.g., < 70) to prevent alert fatigue for agents
        high_value_matches = [m for m in all_matches if m["match_score"] >= 70]
        
        # Sort highest score first
        sorted_matches = sorted(high_value_matches, key=lambda x: x["match_score"], reverse=True)
        
        return {"matches": sorted_matches}
        
    except Exception as e:
        return {"errors": [f"LLM Evaluation Failed: {str(e)}"]}


async def create_notifications(state: MatcherState) -> Dict[str, Any]:
    """Push matched results back to Laravel via M2M tunnel."""
    if not state.get("matches"):
        return {"notifications_created": False}

    payload = {
        "property_id": state["property_data"].get("id"),
        "matches": state["matches"]
    }
    
    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{LARAVEL_URL}/api/v1/internal/ai/alerts/property-matches",
            headers=HEADERS,
            json=payload
        )
        
    return {"notifications_created": response.status_code == 200}