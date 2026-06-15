from pydantic import BaseModel, Field
from typing import List

class PropertyMarketingRequest(BaseModel):
    property_type: str = Field(..., description="e.g., Apartment, Maisonette, Plot")
    location: str = Field(..., description="e.g., Milimani, Nakuru")
    price: str = Field(..., description="The price formatted as a string")
    bedrooms: int | None = None
    bathrooms: int | None = None
    features: List[str] = Field(default=[], description="List of amenities, e.g., ['Borehole', 'Electric Fence']")
    target_audience: str = Field(default="families", description="e.g., families, young professionals, investors")

class PropertyMarketingResponse(BaseModel):
    catchy_title: str = Field(description="A highly engaging, click-worthy title for the property listing.")
    full_description: str = Field(description="A beautifully written, multi-paragraph property description focusing on lifestyle and features.")
    social_media_post: str = Field(description="A short, emoji-rich post optimized for Instagram or Facebook.")
    seo_keywords: List[str] = Field(description="List of 5-7 SEO keywords for the listing.")