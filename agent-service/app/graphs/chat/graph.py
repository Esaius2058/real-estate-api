from typing import Annotated
from typing_extensions import TypedDict
from langgraph.graph import StateGraph, START
from langgraph.graph.message import add_messages
from langgraph.prebuilt import ToolNode, tools_condition
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.messages import SystemMessage

# Import your new tool
from app.tools.inventory import search_properties

class ChatState(TypedDict):
    messages: Annotated[list, add_messages]

# 1. Initialize LLM and Bind Tools
tools = [search_properties]
llm = ChatGoogleGenerativeAI(model="gemini-2.5-flash", temperature=0.1)
llm_with_tools = llm.bind_tools(tools) # This gives the LLM the ability to request tool execution

# 2. Define the Chat Node
async def chat_node(state: ChatState):
    system_prompt = """You are Makao Intelligence, the elite AI concierge for the Makao Real Estate Platform.
    
    CORE DIRECTIVES:
    1. Tone: Exceptionally concise, professional, and authoritative.
    2. Inventory Access: You have a tool to search the live database. ALWAYS use the `search_properties` tool before answering questions about specific listings, locations, or prices. NEVER hallucinate property data.
    3. If the tool returns no results, politely inform the user that there is no matching inventory currently available.
    """
    
    payload = [SystemMessage(content=system_prompt)] + state["messages"]
    
    # We invoke the LLM with the bound tools
    response = await llm_with_tools.ainvoke(payload)
    return {"messages": [response]}

# 3. Compile Graph with Cyclic Routing
builder = StateGraph(ChatState)

# Add standard nodes
builder.add_node("chat", chat_node)
builder.add_node("tools", ToolNode(tools)) # Prebuilt node that executes our python function

builder.add_edge(START, "chat")

# The Magic Router: 
# If the LLM response contains a tool call, route to 'tools'. Otherwise, route to 'END'.
builder.add_conditional_edges("chat", tools_condition)

# After the tool executes and fetches the MySQL data, route BACK to the chat node
# so the LLM can read the data and formulate a natural language response.
builder.add_edge("tools", "chat")

chat_agent = builder.compile()