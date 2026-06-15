from fastapi import APIRouter, Depends, HTTPException
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.prompts import ChatPromptTemplate
from app.models.marketing import PropertyMarketingRequest, PropertyMarketingResponse
from app.core.auth import get_current_user_token
from app.core.config import settings

router = APIRouter(prefix="/agents")

# Using a higher temperature (0.7) because we want creativity for marketing copy
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    google_api_key=settings.GOOGLE_API_KEY,
    temperature=0.7 
)

structured_llm = llm.with_structured_output(PropertyMarketingResponse)

prompt = ChatPromptTemplate.from_messages([
    ("system", """You are an elite, high-end real estate copywriter working for the Makao Platform in Kenya. 
    Your goal is to write compelling, luxurious, and highly converting property descriptions.
    Focus on the lifestyle the property offers. Make it sound irresistible while remaining factually accurate to the provided details."""),
    ("human", """
    Please generate marketing copy for this property:
    
    Type: {property_type}
    Location: {location}
    Price: {price}
    Bedrooms: {bedrooms}
    Bathrooms: {bathrooms}
    Key Features: {features}
    Target Audience: {target_audience}
    """)
])

marketing_chain = prompt | structured_llm

@router.post("/marketing/generate", response_model=PropertyMarketingResponse)
async def generate_marketing_copy(
    request: PropertyMarketingRequest, 
    token: str = Depends(get_current_user_token)
):
    try:
        # Convert the features list to a comma-separated string for the prompt
        features_str = ", ".join(request.features) if request.features else "Standard amenities"
        
        result = await marketing_chain.ainvoke({
            "property_type": request.property_type,
            "location": request.location,
            "price": request.price,
            "bedrooms": request.bedrooms or "N/A",
            "bathrooms": request.bathrooms or "N/A",
            "features": features_str,
            "target_audience": request.target_audience
        })
        return result
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Copywriting Agent Error: {str(e)}")