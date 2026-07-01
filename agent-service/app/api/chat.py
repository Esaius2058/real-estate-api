from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from langchain_core.messages import HumanMessage, AIMessage
import json
from app.db.mysql import AsyncSessionLocal, ChatMessageDB
from app.graphs.chat.graph import chat_agent

router = APIRouter(prefix="/agents")

class ChatRequest(BaseModel):
    message: str
    session_id: str
    agency_id: int

# Dependency to yield DB session
async def get_db():
    async with AsyncSessionLocal() as session:
        yield session

@router.post("/chat")
async def send_message(req: ChatRequest, db: AsyncSession = Depends(get_db)):
    if not req.message or not req.session_id:
        raise HTTPException(status_code=400, detail="Message and session_id are required")

    # 1. Retrieve history from MySQL (Latest 10)
    try:
        stmt = select(ChatMessageDB) \
            .where(ChatMessageDB.session_id == req.session_id) \
            .order_by(ChatMessageDB.created_at.desc()) \
            .limit(10)
        
        result = await db.execute(stmt)
        history_records = result.scalars().all()
        # Reverse to chronological order for the LLM
        history_records.reverse()
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Database read error: {str(e)}")

    # 2. Format history for LangChain
    chat_history = []
    for msg in history_records:
        if msg.role == "user":
            chat_history.append(HumanMessage(content=msg.content))
        elif msg.role == "model":
            chat_history.append(AIMessage(content=msg.content))

    chat_history.append(HumanMessage(content=req.message))

    # 3. Invoke the LangGraph Agent
    try:
        run_config = {"configurable": {"agency_id": req.agency_id}}

        result = await chat_agent.ainvoke({"messages": chat_history}, config=run_config)
        
        raw_content = result["messages"][-1].content
        
        if isinstance(raw_content, list):
            text_pieces = [
                block["text"] for block in raw_content 
                if isinstance(block, dict) and "text" in block
            ]
            ai_response_text = "\n".join(text_pieces) if text_pieces else json.dumps(raw_content)
        elif isinstance(raw_content, dict):
            ai_response_text = raw_content.get("text", json.dumps(raw_content))
        else:
            ai_response_text = str(raw_content)

    except Exception as e:
        error_msg = str(e)
        print(f"❌ LANGGRAPH EXECUTION FAILED: {error_msg}")
        
        # Check specifically for Gemini rate limits
        if "429" in error_msg or "RESOURCE_EXHAUSTED" in error_msg:
            raise HTTPException(
                status_code=429, 
                detail="I'm receiving too many requests right now. Please give me about 30 seconds to catch my breath and try again!"
            )
            
        # Fallback for all other errors
        raise HTTPException(status_code=502, detail=f"LLM processing failed: {error_msg}")
    
    # 4. Save to MySQL
    try:
        # Added agency_id to both database records here:
        user_msg = ChatMessageDB(
            session_id=req.session_id, 
            agency_id=req.agency_id, 
            role="user", 
            content=req.message
        )
        model_msg = ChatMessageDB(
            session_id=req.session_id, 
            agency_id=req.agency_id, 
            role="model", 
            content=ai_response_text
        )
        
        db.add_all([user_msg, model_msg])
        await db.commit()
    except Exception as e:
        await db.rollback()
        print(f"Failed to save chat history: {e}")

    return {"response": ai_response_text}