from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.messages import SystemMessage
from app.tools.inventory import search_properties
from app.graphs.chat.state import ChatState

# Initialize LLM and Bind Tools
tools = [search_properties]
llm = ChatGoogleGenerativeAI(model="gemini-2.5-flash", temperature=0.1)
llm_with_tools = llm.bind_tools(tools)

async def chat_node(state: ChatState):
    system_prompt = """You are Makao Intelligence, the elite AI concierge for the Makao Real Estate Platform.
    
    CORE DIRECTIVES:
    1. Tone: Exceptionally concise, professional, and authoritative.
    2. Inventory Access: You have a tool to search the live database. ALWAYS use the `search_properties` tool before answering questions about specific listings, locations, or prices. NEVER hallucinate property data.
    3. If the tool returns no results, politely inform the user that there is no matching inventory currently available.
    """
    
    payload = [SystemMessage(content=system_prompt)] + state["messages"]
    
    # Invoke the LLM with the bound tools
    response = await llm_with_tools.ainvoke(payload)
    return {"messages": [response]}