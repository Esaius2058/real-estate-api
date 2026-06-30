import asyncio
from fastapi import APIRouter, BackgroundTasks
from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeoutError
from app.graphs.scraper.graph import scraper_app
from app.utils.robots import is_scraping_allowed
from pydantic import BaseModel

router = APIRouter(prefix="/agents")

class ScrapeJobRequest(BaseModel):
    listing_index_url: str
    agency_id:         int
    service_token:     str
    max_listings:      int  = 20
    download_images:   bool = True
    auto_publish:      bool = False
    is_direct_link:    bool = False
    ignore_robots:     bool = False


@router.post("/scrape")
async def start_scrape_job(req: ScrapeJobRequest, background_tasks: BackgroundTasks):
    background_tasks.add_task(run_scrape_job, req)
    return {
        "status": "queued", 
        "message": f"Scraping up to {req.max_listings} listings in background"
    }

async def run_scrape_job(req: ScrapeJobRequest):
    # 1. Smart Routing: Bypass link collection if it's a direct property URL
    if req.is_direct_link:
        urls = [req.listing_index_url]
        print(f"Direct link detected. Scraping exact URL: {urls[0]}")
    else:
        urls = await collect_listing_urls(req.listing_index_url, req.max_listings)

    for url in urls:
        if req.ignore_robots:
            print(f"Ignoring robots.txt restrictions for {url}...")
        elif not is_scraping_allowed(url):
            print(f"Skipping {url} — blocked by robots.txt")
            continue

        try:
            await scraper_app.ainvoke({
                "target_url":    url,
                "agency_id":     req.agency_id,
                "service_token": req.service_token,
                "raw_html":      None,
                "cleaned_text":  None,
                "extracted_data": None,
                "image_urls":    [],
                "supabase_paths": [],
                "property_id":   None,
                "errors":        [],
                "retry_count":   0,
                "ignore_robots": req.ignore_robots,
            })
        except Exception as e:
            print(f"Failed for {url}: {e}")

        # Respectful delay — BuyRentKenya uses Cloudflare
        await asyncio.sleep(4)

async def collect_listing_urls(index_url: str, limit: int) -> list[str]:
    urls = []
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox", 
                "--disable-dev-shm-usage", 
                "--disable-gpu",
                "--single-process", 
                "--no-zygote",
                "--disable-blink-features=AutomationControlled" # Bypasses webdriver detection
            ]
        )
        try:
            page = await browser.new_page()
            
            # Mask as a standard desktop browser
            await page.set_extra_http_headers({
                "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
            })

            # Catch the timeout instead of crashing
            try:
                await page.goto(index_url, wait_until="networkidle", timeout=45000)
            except PlaywrightTimeoutError:
                print(f"Timeout hit for {index_url}. Proceeding to extract loaded DOM.")

            # Hard sleep to ensure React/Next.js hydration finishes rendering the anchor tags
            await page.wait_for_timeout(3000)

            links = await page.eval_on_selector_all(
                "a[href*='/property/'], a[href*='/listing/'], a[href*='/for-sale/'], a[href*='/to-let/']",
                "els => els.map(e => e.href)"
            )
            urls = list(set(links))[:limit]
        finally:
            await browser.close()
            
    return urls