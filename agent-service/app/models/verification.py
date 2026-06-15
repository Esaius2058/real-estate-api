from pydantic import BaseModel, Field

# --- What Laravel sends to the Agent Service ---
class VerificationRequest(BaseModel):
    ocr_text: str = Field(..., description="The raw OCR text extracted from the document.")
    expected_name: str = Field(..., description="The user's registered name in the database.")
    expected_type: str = Field(..., description="The type of document uploaded (e.g., 'National ID', 'Passport').")

# --- What Gemini must return (Structured Output) ---
class VerificationResult(BaseModel):
    extracted_id_number: str | None = Field(
        description="The primary identification number extracted from the document. Null if not found."
    )
    extracted_name: str | None = Field(
        description="The full name extracted from the document."
    )
    name_match: bool = Field(
        description="True if the extracted name reasonably matches the expected_name (allowing for middle names or slight misspellings)."
    )
    document_type_confirmed: bool = Field(
        description="True if the document appears to actually be the expected_type."
    )
    confidence_score: float = Field(
        description="A score from 0.0 to 100.0 representing confidence in the extraction and match."
    )
    reasoning: str = Field(
        description="A brief explanation of why the document was flagged or verified, noting any discrepancies."
    )