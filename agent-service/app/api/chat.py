from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Any, Union
from langchain_core.messages import HumanMessage, AIMessage

# Import your compiled graph
from app.graphs.chat.graph import chat_agent

router = APIRouter(prefix="/agents")

class ChatRequest(BaseModel):
    message: str
    session_id: str
    agency_id: Union[int, str] # Accept both int and string from PHP
    history: List[Dict[str, Any]] = Field(default_factory=list) # Forgive null values in history

@router.post("/chat")
async def chat_endpoint(req: ChatRequest):
    try:
        # 1. Initialize the LangChain memory array
        chat_messages = []
        
        # 2. Parse the history sent by Laravel into LangChain objects
        for msg in req.history:
            raw_content = msg.get("content")
            
            # Handle list content (LangChain natively supports list formats for multimodal/blocks)
            if isinstance(raw_content, list):
                if len(raw_content) == 0:
                    continue
                safe_content = raw_content
                
            # Handle standard string content
            elif isinstance(raw_content, str):
                if not raw_content.strip():
                    continue
                safe_content = raw_content
                
            # Handle None or any other unexpected types
            else:
                if not raw_content:
                    continue
                safe_content = str(raw_content)
                
            # Safely instantiate the messages
            if msg.get("role") == "user":
                chat_messages.append(HumanMessage(content=safe_content))
            elif msg.get("role") == "model":
                chat_messages.append(AIMessage(content=safe_content))
                
        # 3. Append the new, incoming message from the user
        chat_messages.append(HumanMessage(content=req.message))
        
        # 4. Invoke LangGraph with the formatted messages
        # Pass agency_id in config so your tools can access it securely
        run_config = {"configurable": {"thread_id": req.session_id, "agency_id": req.agency_id}}
        
        result = await chat_agent.ainvoke({"messages": chat_messages}, config=run_config)
        
        # 5. Extract the raw string from the AI's final message object
        ai_response_text = result["messages"][-1].content
        
        # Flatten Gemini's multi-block arrays into a single string for the UI
        if isinstance(ai_response_text, list):
            flattened_text = []
            for block in ai_response_text:
                if isinstance(block, dict) and "text" in block:
                    flattened_text.append(block["text"])
                elif isinstance(block, str):
                    flattened_text.append(block)
            
            ai_response_text = " ".join(flattened_text)
            
            # Fallback if no text was found (e.g., it was purely a tool call)
            if not ai_response_text.strip():
                ai_response_text = "I have processed that request using my tools."

        return {"response": ai_response_text}
        
    except Exception as e:
        import traceback
        print(traceback.format_exc()) # Prints exact failure line to your terminal
        raise HTTPException(status_code=500, detail=str(e))