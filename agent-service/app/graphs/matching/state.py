from typing import TypedDict, List, Dict, Any

class MatcherState(TypedDict):
    property_data: Dict[str, Any]
    agency_id: int
    leads: List[Dict[str, Any]]
    matches: List[Dict[str, Any]]
    notifications_created: bool
    errors: List[str]