import os
import json
from typing import TypedDict, List, Dict, Any
from langgraph.graph import StateGraph, END
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.prompts import ChatPromptTemplate
from app.tools.laravel_client import LaravelClient

# 1. Define the State
class LeadMatchState(TypedDict):
    lead_data: Dict[str, Any]
    agency_id: int
    properties: List[Dict[str, Any]]
    matches: List[Dict[str, Any]]
    errors: List[str]

# 2. Nodes
async def fetch_active_properties(state: LeadMatchState):
    """Fetches all active properties for the agency from Laravel."""
    try:
        # Note: Initialize LaravelClient with your M2M token here
        client = LaravelClient() 
        response = await client.get(f"internal/ai/agencies/{state['agency_id']}/properties")
        properties = response.get("data", [])
        return {"properties": properties}
    except Exception as e:
        return {"errors": [f"Failed to fetch properties: {str(e)}"]}

async def evaluate_properties(state: LeadMatchState):
    """Evaluates the single lead against all fetched properties."""
    if not state.get("properties") or state.get("errors"):
        return state

    llm = ChatGoogleGenerativeAI(
        model="gemini-2.5-flash", 
        temperature=0.1,
        api_key=os.getenv("GOOGLE_API_KEY")
    )
    
    prompt = ChatPromptTemplate.from_messages([
        ("system", "You are an AI real estate matcher. Compare this Lead's requirements against the provided list of Properties. Return a strict JSON array of matches scoring 75 or higher. Schema: [{ 'property_id': int, 'score': int, 'reasoning': 'string' }]. Return ONLY valid JSON."),
        ("human", "Lead Data: {lead}\n\nProperties: {properties}")
    ])
    
    chain = prompt | llm
    
    try:
        # To avoid token limits on massive databases, you would batch this in production.
        # For the prototype, we pass the array directly.
        result = await chain.ainvoke({
            "lead": json.dumps(state["lead_data"]),
            "properties": json.dumps(state["properties"])
        })
        
        # Clean markdown formatting if Gemini adds it
        raw_json = result.content.replace("```json", "").replace("```", "").strip()
        evaluated_matches = json.loads(raw_json)
        
        # Format for Laravel Webhook
        final_matches = []
        for match in evaluated_matches:
            final_matches.append({
                "lead_id": state["lead_data"]["id"],
                "property_id": match["property_id"],
                "score": match["score"],
                "reasoning": match["reasoning"]
            })
            
        return {"matches": final_matches}
        
    except Exception as e:
        return {"errors": [f"AI Evaluation failed: {str(e)}"]}

async def dispatch_notifications(state: LeadMatchState):
    """Sends the successful matches back to Laravel's AlertController."""
    if not state.get("matches") or state.get("errors"):
        return state
        
    try:
        client = LaravelClient()
        await client.post("internal/ai/alerts/property-matches", {
            "matches": state["matches"],
            "agency_id": state["agency_id"]
        })
        return state
    except Exception as e:
        return {"errors": [f"Failed to dispatch alerts: {str(e)}"]}

# 3. Build the Graph
workflow = StateGraph(LeadMatchState)
workflow.add_node("fetch_active_properties", fetch_active_properties)
workflow.add_node("evaluate_properties", evaluate_properties)
workflow.add_node("dispatch_notifications", dispatch_notifications)

workflow.set_entry_point("fetch_active_properties")
workflow.add_edge("fetch_active_properties", "evaluate_properties")
workflow.add_edge("evaluate_properties", "dispatch_notifications")
workflow.add_edge("dispatch_notifications", END)

lead_matching_app = workflow.compile()