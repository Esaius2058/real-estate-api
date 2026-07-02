import httpx
import traceback
from langchain_core.tools import tool
from langchain_core.runnables.config import RunnableConfig
from app.core.config import settings

@tool
async def search_properties(
    config: RunnableConfig,
    location: str = None,
    min_bedrooms: int = None,
    max_price: float = None,
) -> str:
    """
    Searches the live property inventory for this agency.
    Use this whenever a user asks about available properties, prices, locations,
    bedroom count, or any listing-related query.
    """
    print(f"\n{'='*40}")
    print(f"[DEBUG TOOL] search_properties triggered!")
    print(f"[DEBUG TOOL] LLM Args -> location: {location}, min_bedrooms: {min_bedrooms}, max_price: {max_price}")
    
    agency_id = config.get("configurable", {}).get("agency_id")
    print(f"[DEBUG TOOL] Config Agency ID -> {agency_id}")
    
    if not agency_id:
        print("[DEBUG TOOL] FAILED: Missing agency context in config.")
        return "System error: missing agency context. Cannot query inventory."

    url = f"{settings.LARAVEL_API_URL}/api/v1/internal/ai/agencies/{agency_id}/properties"
    print(f"[DEBUG TOOL] Target URL -> {url}")
    
    headers = {
        "Authorization": f"Bearer {settings.LARAVEL_M2M_TOKEN}",
        "Accept": "application/json",
    }
    # Print partial token to verify it's actually loaded from .env
    token_preview = str(settings.LARAVEL_M2M_TOKEN)[:5] + "..." if settings.LARAVEL_M2M_TOKEN else "MISSING!"
    print(f"[DEBUG TOOL] M2M Token starts with -> {token_preview}")

    try:
        async with httpx.AsyncClient() as client:
            response = await client.get(url, headers=headers, timeout=10.0)

        print(f"[DEBUG TOOL] Laravel HTTP Status -> {response.status_code}")
        # Print first 500 chars of response to catch HTML error pages or JSON errors
        print(f"[DEBUG TOOL] Raw Laravel Response -> {response.text[:500]}")

        if response.status_code != 200:
            return f"Could not retrieve inventory (HTTP {response.status_code})."

        properties = response.json().get("data", [])
        print(f"[DEBUG TOOL] Properties parsed from JSON -> {len(properties)} found.")

    except httpx.TimeoutException:
        print("[DEBUG TOOL] FAILED: Request timed out.")
        return "Request to the property inventory timed out. Try again shortly."
    except Exception as e:
        print(f"[DEBUG TOOL] EXCEPTION CAUGHT:")
        traceback.print_exc() # Prints the full stack trace to your terminal
        return f"Connectivity error while querying inventory: {str(e)}"

    if not properties:
        print("[DEBUG TOOL] Returned successful, but no active listings found.")
        return "No active listings found for this agency."

    # In-memory filtering based on LLM-supplied parameters
    filtered = []
    for p in properties:
        if location and (
            location.lower() not in str(p.get("location", "")).lower() and
            location.lower() not in str(p.get("city", "")).lower()
        ):
            continue
        if min_bedrooms is not None and int(p.get("bedrooms") or 0) < int(min_bedrooms):
            continue
        if max_price is not None and float(p.get("price") or 0) > float(max_price):
            continue
        filtered.append(p)

    print(f"[DEBUG TOOL] Filtered down to {len(filtered)} properties based on LLM args.")

    if not filtered:
        return (
            f"No listings match those criteria "
            f"({'in ' + location if location else ''}"
            f"{', min ' + str(min_bedrooms) + ' beds' if min_bedrooms else ''}"
            f"{', under KES ' + str(int(max_price)) if max_price else ''}"
            f"). The agent can adjust the search filters."
        )

    # Format top 5 for the LLM — clean, token-efficient
    lines = []
    for p in filtered[:5]:
        beds   = p.get("bedrooms") or "N/A"
        baths  = p.get("baths")    or "N/A"
        sqft   = p.get("sqft")     or "N/A"
        price  = p.get("price")    or "N/A"
        ptype  = p.get("type")     or "Property"
        loc    = p.get("location") or p.get("city") or "Unknown"
        city   = p.get("city")     or ""
        desc   = str(p.get("description") or "")[:120]

        lines.append(
            f"[ID {p.get('id', 'N/A')}] {beds}-bed {ptype} in {loc}, {city}\n"
            f"  Price: KES {price} | Baths: {baths} | Size: {sqft} sqft\n"
            f"  {desc}{'...' if len(str(p.get('description',''))) > 120 else ''}"
        )

    total = len(filtered)
    header = f"Found {total} matching listing{'s' if total != 1 else ''}. Showing top {min(5, total)}:\n"
    print(f"[DEBUG TOOL] Execution complete. Returning data to LLM.")
    print(f"{'='*40}\n")
    return header + "\n---\n".join(lines)