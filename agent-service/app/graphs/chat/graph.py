from langgraph.graph import StateGraph, START
from langgraph.prebuilt import ToolNode, tools_condition
from app.graphs.chat.state import ChatState
from app.graphs.chat.nodes import chat_node, tools

# Compile Graph with Cyclic Routing
builder = StateGraph(ChatState)

# Add standard nodes
builder.add_node("chat", chat_node)
builder.add_node("tools", ToolNode(tools))

builder.add_edge(START, "chat")

# Conditional routing
builder.add_conditional_edges("chat", tools_condition)

# Return loop
builder.add_edge("tools", "chat")

chat_agent = builder.compile()