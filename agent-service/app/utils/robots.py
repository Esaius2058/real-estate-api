import urllib.robotparser
from urllib.parse import urlparse

def is_scraping_allowed(url: str, user_agent: str = "*") -> bool:
    try:
        parsed    = urlparse(url)
        robots_url = f"{parsed.scheme}://{parsed.netloc}/robots.txt"

        rp = urllib.robotparser.RobotFileParser()
        rp.set_url(robots_url)
        rp.read()

        return rp.can_fetch(user_agent, url)
    except Exception:
        # If robots.txt can't be fetched, allow by default
        return True