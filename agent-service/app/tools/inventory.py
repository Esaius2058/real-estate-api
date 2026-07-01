import httpx
from langchain_core.tools import tool
from langchain_core.runnables.config import RunnableConfig
from app.core.config import settings

@tool
async def search_properties(
    config: RunnableConfig,
    location: str = None, 
    min_bedrooms: int = None, 
    max_price: float = None
) -> str:
    """
    Searches the live system inventory for available real estate listings.
    Use this tool whenever a user asks about available properties, prices, or locations.
    """
    agency_id = config.get("configurable", {}).get("agency_id")
    if not agency_id:
        return "System Error: Missing agency context window."

    # Hit the explicit internal Laravel endpoint you defined
    url = f"{settings.LARAVEL_API_URL}/api/internal/ai/agencies/{agency_id}/properties"
    
    headers = {
        "Authorization": f"Bearer {settings.LARAVEL_M2M_TOKEN}",
        "Accept": "application/json"
    }

    async with httpx.AsyncClient() as client:
        try:
            response = await client.get(url, headers=headers, timeout=10.0)
            if response.status_code != 200:
                return "Could not retrieve property inventory from core system."
            
            payload = response.json()
            properties = payload.get("data", [])
            
        except Exception as e:
            return f"Internal connectivity error while querying system inventory: {str(e)}"

    if not properties:
        return "No matching active inventory found for this agency."

    # Filter data in-memory based on parameters passed by the LLM
    filtered_properties = []
    for p in properties:
        # Match location
        if location and location.lower() not in p.get("location", "").lower() and location.lower() not in p.get("city", "").lower():
            continue
        # Match bedrooms
        if min_bedrooms and int(p.get("bedrooms", 0)) < int(min_bedrooms):
            continue
        # Match price
        if max_price and float(p.get("price", 0)) > float(max_price):
            continue
            
        filtered_properties.append(p)

    if not filtered_properties:
        return "No properties match those specific criteria within our active catalog."

    # Format the array output cleanly for the Gemini node
    formatted_results = []
    for p in filtered_properties[:5]:  # Enforce limit of 5 records
        formatted_results.append(
            f"ID {p['id']}: {p['bedrooms']}-bed {p['type']} in {p['location']}, {p['city']}. Price: KES {p['price']}. Description: {p['description']}"
        )
    
    return "\n---\n".join(formatted_results)