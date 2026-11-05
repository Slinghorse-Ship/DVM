#!/usr/bin/env python3
"""Check the official DATEV SKR42 edition pages without changing the active catalog.

The result is a review manifest. It deliberately never replaces a local JSON catalog.
Use --download-dir to keep the official PDF bytes as a pending candidate; activate a
parsed catalog only after a human review and a hash check.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import html
import json
import pathlib
import re
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from datev_years import official_url

EDITIONS = {
    "euer": "https://www.datev.de/web/de/shop/produkt-details/datev-kontenrahmen-skr-42-vereine-stiftungen-ggmbh-4-abs-3-estg-12902",
    "balance": "https://www.datev.de/web/de/shop/produkt-details/12901",
}


class Links(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.hrefs: list[str] = []
        self.text: list[str] = []
        self.download_hrefs: list[str] = []
        self.link: str | None = None
        self.link_text: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag.lower() == "a":
            value = dict(attrs).get("href")
            if value:
                self.hrefs.append(html.unescape(value))
                self.link=html.unescape(value);self.link_text=[]

    def handle_data(self, data: str) -> None:
        self.text.append(data)
        if self.link:self.link_text.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag.lower()=='a' and self.link:
            if re.search(r'kostenfreier\s+download',' '.join(self.link_text),re.IGNORECASE):self.download_hrefs.append(self.link)
            self.link=None;self.link_text=[]


class OfficialRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        if not official_url(newurl):
            raise ValueError('Download leitet auf eine ungeprüfte Quelle weiter.')
        return super().redirect_request(request,fp,code,msg,headers,newurl)


def fetch(url: str, limit: int = 4*1024*1024) -> bytes:
    if not official_url(url):
        raise ValueError('Offizielle HTTPS-Quelle erforderlich.')
    request = urllib.request.Request(url, headers={"User-Agent": "Kulturhus-SKR42-Check/1.0"})
    with urllib.request.build_opener(OfficialRedirect()).open(request, timeout=30) as response:
        if not official_url(response.geturl()):raise ValueError('Ungeprüfte Downloadquelle.')
        content=response.read(limit+1)
        if len(content)>limit:raise ValueError('Download ist größer als erwartet.')
        return content


def official_download(page_url: str, hrefs: list[str], preferred: list[str] | None = None) -> str | None:
    candidates = set()
    for href in hrefs:
        url = urllib.parse.urljoin(page_url, href)
        parsed = urllib.parse.urlparse(url)
        host = (parsed.hostname or "").lower()
        # The header also links to the DATEV remote-support download. It is NOT
        # the product PDF. Multiple plausible PDFs need a source-layout review.
        if official_url(url) and (host=='download.datev-shop.de' or parsed.path.lower().endswith('.pdf')):
            candidates.add(url)
    labeled={urllib.parse.urljoin(page_url,href) for href in (preferred or [])}&candidates
    # DATEV may offer the same product both as a media-library PDF and through
    # its labelled shop delivery endpoint. Prefer that single delivery endpoint.
    delivered={url for url in labeled if urllib.parse.urlparse(url).hostname=='download.datev-shop.de'}
    if delivered:return next(iter(delivered)) if len(delivered)==1 else None
    if labeled:return next(iter(labeled)) if len(labeled)==1 else None
    return next(iter(candidates)) if len(candidates)==1 else None


def edition(text: str) -> str | None:
    match = re.search(r"Stand\s+([A-Za-zÄÖÜäöüß]+\s+20\d{2})", text, re.IGNORECASE)
    return " ".join(match.group(1).split()) if match else None


def download_pdf(url: str) -> tuple[str, bytes]:
    raw=fetch(url,32*1024*1024)
    if raw.startswith(b'%PDF-'):return url,raw
    # The official shop uses a small HTML delivery page with an ordinary link
    # as its JS-redirect fallback. Follow that observed link once; never execute
    # its script or construct/guess a file ID.
    links=Links();links.feed(raw.decode('utf-8',errors='replace'))
    target=official_download(url,links.hrefs,links.download_hrefs)
    if not target or target==url:raise ValueError('Download ist keine PDF-Datei; eindeutiger Dateilink fehlt.')
    raw=fetch(target,32*1024*1024)
    if not raw.startswith(b'%PDF-'):raise ValueError('Download ist keine PDF-Datei.')
    return target,raw


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1] / "data" / "datev-skr42-status.json")
    parser.add_argument("--download-dir", type=pathlib.Path, help="Store official PDFs as pending files; never activates them")
    args = parser.parse_args()
    checked_at = dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    result = {"checked_at": checked_at, "policy": "review_before_activation", "editions": {}}
    for variant, page_url in EDITIONS.items():
        row = {"variant": variant, "page_url": page_url, "state": "error"}
        try:
            page_bytes = fetch(page_url)
            parser_html = Links()
            parser_html.feed(page_bytes.decode("utf-8", errors="replace"))
            page_text = " ".join(parser_html.text)
            download_url = official_download(page_url, parser_html.hrefs,parser_html.download_hrefs)
            row.update({
                "state": "found" if download_url else "download_link_missing",
                "edition": edition(page_text),
                "page_sha256": hashlib.sha256(page_bytes).hexdigest(),
                "download_url": download_url,
            })
            if args.download_dir and download_url:
                row['landing_url']=download_url
                download_url,pdf_bytes=download_pdf(download_url)
                row['download_url']=download_url
                args.download_dir.mkdir(parents=True, exist_ok=True)
                digest = hashlib.sha256(pdf_bytes).hexdigest()
                target = args.download_dir / f"skr42-{variant}-{digest}.pdf"
                if target.exists():
                    if target.read_bytes()!=pdf_bytes:raise ValueError('Vorhandener Kandidat wurde verändert.')
                else:
                    with target.open('xb') as file:file.write(pdf_bytes)
                row.update({"pdf_sha256": digest, "pending_pdf": str(target.resolve()), "pdf_bytes": len(pdf_bytes)})
        except Exception as error:  # noqa: BLE001 - one unavailable edition must not hide the other
            row['state']='error'
            row["error"] = f"{type(error).__name__}: {error}"
        result["editions"][variant] = row
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if all(row["state"] == "found" for row in result["editions"].values()) else 2


if __name__ == "__main__":
    raise SystemExit(main())
