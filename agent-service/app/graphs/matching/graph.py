from langgraph.graph import StateGraph, END
from .state import MatcherState
from .nodes import fetch_leads, match_reasoning, create_notifications

# Initialize
workflow = StateGraph(MatcherState)

# Add Nodes
workflow.add_node("fetch_leads", fetch_leads)
workflow.add_node("match_reasoning", match_reasoning)
workflow.add_node("create_notifications", create_notifications)

# Define Routing
workflow.set_entry_point("fetch_leads")
workflow.add_edge("fetch_leads", "match_reasoning")
workflow.add_edge("match_reasoning", "create_notifications")
workflow.add_edge("create_notifications", END)

# Compile
matching_app = workflow.compile()