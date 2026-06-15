from fastapi import APIRouter, Depends, HTTPException
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.prompts import ChatPromptTemplate
from app.models.verification import VerificationRequest, VerificationResult
from app.core.auth import get_current_user_token
from app.core.config import settings

router = APIRouter(prefix="/agents")

# Initialize Gemini Flash
# Using temperature=0.1 because KYC verification requires strict, deterministic extraction
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    api_key=settings.GOOGLE_API_KEY,
    temperature=0.1 
)

# Bind the LLM to our Pydantic model so it strictly returns JSON matching our schema
structured_llm = llm.with_structured_output(VerificationResult)

# Define the Prompt
prompt = ChatPromptTemplate.from_messages([
    ("system", """You are a strict, highly accurate KYC (Know Your Customer) compliance officer for a real estate platform in Kenya. 
    Your job is to analyze raw, messy OCR text extracted from an identity document and compare it against the expected user details.
    
    Allow for minor OCR errors (e.g., '0' instead of 'O') and missing middle names, but flag major discrepancies.
    If the text is completely illegible or clearly not an ID/Passport, set confidence_score low and explain why."""),
    ("human", """
    Expected Document Type: {expected_type}
    Expected User Name: {expected_name}
    
    Raw OCR Text:
    {ocr_text}
    """)
])

# Create the LangChain processing chain
verification_chain = prompt | structured_llm

@router.post("/verify", response_model=VerificationResult)
async def verify_kyc_document(
    request: VerificationRequest, 
    token: str = Depends(get_current_user_token)
):
    """
    Analyzes OCR text and returns a structured verification assessment.
    Requires a valid Sanctum token from Laravel.
    """
    try:
        # Execute the chain
        result = await verification_chain.ainvoke({
            "expected_type": request.expected_type,
            "expected_name": request.expected_name,
            "ocr_text": request.ocr_text
        })
        return result
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"AI Processing Error: {str(e)}")