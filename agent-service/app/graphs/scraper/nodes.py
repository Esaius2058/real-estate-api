from langsmith import traceable
import uuid
import aiohttp
import asyncio
from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeoutError
from bs4 import BeautifulSoup
from bs4 import BeautifulSoup
from pydantic import BaseModel, Field
from typing import List, Optional
from langchain_google_genai import ChatGoogleGenerativeAI
import httpx

from app.utils.robots import is_scraping_allowed
from app.core.config import settings
from .state import ScraperState

# ── Pydantic schema for extraction ────────────────────────────────────────────
class ExtractedProperty(BaseModel):
    title:       str            = Field(description="The main headline or title of the listing")
    price:       str            = Field(description="Numeric price in KES only. Strip commas and currency symbols. e.g. '8500000'")
    location:    str            = Field(description="The neighborhood or estate e.g. Kileleshwa, Runda, Bamburi")
    city:        str            = Field(description="The city. Default to Nairobi if not specified")
    bedrooms:    Optional[int]  = Field(None, description="Number of bedrooms")
    baths:       Optional[int]  = Field(None, description="Number of bathrooms")
    sqft:        Optional[int]  = Field(None, description="Square footage or square metres if available")
    description: str            = Field(description="Full text description of the property")
    amenities:   List[str]      = Field(default_factory=list, description="List of features e.g. Swimming Pool, CCTV, Borehole")
    image_urls:  List[str]      = Field(default_factory=list, description="Direct URLs to high-res property images ending in .jpg .png or .webp")
    property_type: Optional[str] = Field(None, description="Type of property e.g. Apartment, Villa, Land, Commercial")

# ── Shared LLM instance ───────────────────────────────────────────────────────
_llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    temperature=0,
    api_key=settings.GOOGLE_API_KEY,
)
_structured_llm = _llm.with_structured_output(ExtractedProperty)

# ── Node 1: Crawl ─────────────────────────────────────────────────────────────
@traceable(name="crawl", run_type="tool")
async def crawl_node(state: ScraperState) -> dict:
    print(f"DEBUG: Entering crawl_node for {state['target_url']}")

    errors      = list(state["errors"])
    retry_count = state["retry_count"]
    target_url  = state["target_url"] 
    ignore_robots = state.get("ignore_robots", False)

    # Only enforce robots.txt if the bypass flag is false
    if not ignore_robots and not is_scraping_allowed(target_url):
        errors.append(f"robots.txt disallows scraping: {target_url}")
        return {"cleaned_text": None, "errors": errors, "retry_count": 3}

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--single-process",
                "--no-zygote",
                "--disable-blink-features=AutomationControlled" # FIXED: Cloudflare bypass
            ]
        )
        try:
            page = await browser.new_page()
            await page.set_extra_http_headers({
                "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                "Referer":    "/".join(target_url.split("/")[:3]),
            })
            
            # FIXED: Graceful timeout handling
            try:
                await page.goto(target_url, wait_until="networkidle", timeout=45000)
            except PlaywrightTimeoutError:
                print(f"[Node: Crawl] Timeout on {target_url}. Proceeding with loaded DOM.")

            # Wait for React to mount the image carousels
            await page.wait_for_timeout(3000)
            
            html = await page.content()

            image_urls = await page.eval_on_selector_all(
                "img[src*='.jpg'], img[src*='.jpeg'], img[src*='.png'], img[src*='.webp']",
                "els => els.map(e => e.src).filter(s => !s.includes('logo') && !s.includes('icon') && !s.includes('avatar'))"
            )

            if not html or len(html) < 500:
                print(f"DEBUG: Page empty or blocked. HTML length: {len(html)}")

        except Exception as e:
            errors.append(f"Crawl failed: {str(e)}")
            return {
                "cleaned_text": None,
                "image_urls":   [],
                "errors":       errors,
                "retry_count":  retry_count + 1,
            }
        finally:
            await browser.close()

    soup = BeautifulSoup(html, "html.parser")
    for tag in soup(["script", "style", "svg", "nav", "footer", "header"]):
        tag.decompose()

    cleaned = soup.get_text(separator=" ", strip=True)

    if len(cleaned) > 12000:
        cleaned = cleaned[:12000]

    return {
        "cleaned_text": cleaned,
        "image_urls":   image_urls[:10],
        "errors":       errors,
        "retry_count":  retry_count + 1,
    }

# ── Node 2: Extract ───────────────────────────────────────────────────────────
@traceable(name="data_extraction", run_type="tool")
async def extract_node(state: ScraperState) -> dict:
    errors = list(state["errors"])

    if not state.get("cleaned_text"):
        errors.append("extract_node: no cleaned_text available to extract from")
        return {"extracted_data": None, "errors": errors}

    prompt = f"""
You are an expert real estate data extractor specialising in the Kenyan property market.

Analyse the following scraped webpage text from a Kenyan real estate listing and extract
all available property details. Follow these rules strictly:

- Convert all prices to raw integers with no commas or currency symbols.
  e.g. 'KES 8,500,000' or 'Kshs 8.5M' both become '8500000'
- If bedrooms, baths, or sqft are not mentioned, return null — do not guess.
- For amenities, extract individual items as a clean list.
  e.g. ['Swimming Pool', 'Backup Generator', 'CCTV', 'Borehole']
- For image_urls, only include direct URLs ending in .jpg .jpeg .png or .webp.
  Do not include placeholder, icon, or logo images.
- If the listing is for land with no bedrooms, set bedrooms and baths to null.
- Default city to 'Nairobi' if the city is not explicitly mentioned.
- property_type should be one of: Apartment, Maisonette, Villa, Townhouse,
  Bungalow, Land, Commercial, Studio. Pick the closest match.

Webpage Text:
{state['cleaned_text']}
"""

    try:
        result: ExtractedProperty = await _structured_llm.ainvoke(prompt)

        # Merge image URLs: prefer DOM-extracted ones from crawl_node,
        # supplement with any the LLM found in the text
        dom_images = state.get("image_urls", [])
        llm_images = result.image_urls or []
        merged_images = list(dict.fromkeys(dom_images + llm_images))[:10]

        extracted = result.model_dump()
        extracted["image_urls"] = merged_images

        return {
            "extracted_data": extracted,
            "image_urls":     merged_images,
            "errors":         errors,
        }

    except Exception as e:
        errors.append(f"Extraction failed: {str(e)}")
        return {"extracted_data": None, "errors": errors}


# ── Node 3: Media ─────────────────────────────────────────────────────────────
@traceable(name="media_scraper", run_type="tool")
async def media_node(state: ScraperState) -> dict:
    errors         = list(state["errors"])
    image_urls     = state.get("image_urls", [])
    supabase_paths = []

    if not image_urls:
        return {"supabase_paths": [], "errors": errors}

    # Import here to avoid circular imports with supabase client setup
    from app.core.supabase import supabase_client

    async with aiohttp.ClientSession(
        headers={
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        },
        timeout=aiohttp.ClientTimeout(total=20)
    ) as session:

        for url in image_urls:
            # Small delay between image downloads — avoids hammering CDNs
            await asyncio.sleep(0.5)

            try:
                async with session.get(url) as response:
                    if response.status != 200:
                        errors.append(f"Image download returned {response.status}: {url}")
                        continue

                    # Validate content type before uploading
                    content_type = response.headers.get("Content-Type", "")
                    if not any(t in content_type for t in ["image/jpeg", "image/png", "image/webp"]):
                        errors.append(f"Skipped non-image content-type '{content_type}': {url}")
                        continue

                    image_data = await response.read()

                    # Reject suspiciously small files — likely placeholders or 1px trackers
                    if len(image_data) < 10_000:
                        errors.append(f"Skipped suspiciously small image ({len(image_data)} bytes): {url}")
                        continue

                    ext      = url.split(".")[-1].split("?")[0].lower() or "jpg"
                    filename = f"makao/property_images/scraped/{uuid.uuid4()}.{ext}"

                    supabase_client.storage.from_("user-files").upload(
                        path=filename,
                        file=image_data,
                        file_options={"content-type": content_type}
                    )

                    supabase_paths.append(filename)

            except asyncio.TimeoutError:
                errors.append(f"Image download timed out: {url}")
            except Exception as e:
                errors.append(f"Image download failed for {url}: {str(e)}")

    return {"supabase_paths": supabase_paths, "errors": errors}


# ── Node 4: Post to Laravel ───────────────────────────────────────────────────
@traceable(name="publish_to_laravel", run_type="tool")
async def post_to_laravel_node(state: ScraperState) -> dict:
    errors = list(state["errors"])
    data   = state.get("extracted_data")
    paths  = state.get("supabase_paths", [])

    if not data:
        errors.append("post_to_laravel_node: no extracted_data to post")
        return {"property_id": None, "errors": errors}

    payload = {
        "title":       data.get("title"),
        "price":       data.get("price"),
        "location":    data.get("location"),
        "city":        data.get("city", "Nairobi"),
        "bedrooms":    data.get("bedrooms"),
        "baths":       data.get("baths"),
        "sqft":        data.get("sqft"),
        "description": data.get("description"),
        "status":      "active",
        "type":        data.get("property_type"),
        "amenities":   data.get("amenities", []),
        "images": {
            "main":     paths[0]  if len(paths) > 0 else None,
            "interior": paths[1:4] if len(paths) > 1 else [],
            "exterior": paths[4:]  if len(paths) > 4 else [],
        },
    }

    try:
        async with httpx.AsyncClient() as client:
            response = await client.post(
                f"{settings.LARAVEL_API_URL}/api/v1/internal/ai/properties/scraped",
                json=payload,
                headers={
                    "Authorization": f"Bearer {state['service_token']}",
                    "Accept":        "application/json",
                },
                timeout=30,
            )
            response.raise_for_status()
            property_id = response.json().get("data", {}).get("id")
            print(f"Created property ID {property_id}: {data.get('title')}")
            return {"property_id": property_id, "errors": errors}

    except httpx.HTTPStatusError as e:
        errors.append(f"Laravel rejected property: {e.response.status_code} — {e.response.text}")
        return {"property_id": None, "errors": errors}
    except Exception as e:
        errors.append(f"Laravel post failed: {str(e)}")
        return {"property_id": None, "errors": errors}