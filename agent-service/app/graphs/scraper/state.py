from typing import TypedDict, List, Optional, Any

class ScraperState(TypedDict):
    # Input
    target_url:        str
    agency_id:         int
    service_token:     str        # Sanctum token for posting to Laravel
    # Intermediate
    raw_html:          Optional[str]
    cleaned_text:      Optional[str]
    extracted_data:    Optional[dict]
    image_urls:        List[str]
    # Output
    supabase_paths:    List[str]
    property_id:       Optional[int]   # ID of created property in Laravel
    errors:            List[str]
    retry_count:       int
    ignore_robots: bool