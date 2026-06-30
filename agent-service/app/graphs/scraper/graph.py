from langgraph.graph import StateGraph, END
from .state import ScraperState
from .nodes import crawl_node, extract_node, media_node, post_to_laravel_node

def build_scraper_graph():
    graph = StateGraph(ScraperState)

    graph.add_node("crawl",     crawl_node)
    graph.add_node("extract",   extract_node)
    graph.add_node("media",     media_node)
    graph.add_node("post",      post_to_laravel_node)

    graph.set_entry_point("crawl")

    graph.add_conditional_edges("crawl", lambda s: (
        "extract" if s["cleaned_text"] else
        "crawl"   if s["retry_count"] < 3 else END
    ))
    graph.add_conditional_edges("extract", lambda s: (
        "media" if s["extracted_data"] and len(s["cleaned_text"]) > 50 else
        "crawl" if s["retry_count"] < 3 else END
    ))
    graph.add_edge("media", "post")
    graph.add_edge("post",  END)

    return graph.compile()

scraper_app = build_scraper_graph()