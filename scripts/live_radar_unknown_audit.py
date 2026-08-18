#!/usr/bin/env python3
"""Audit 3 h des lives radar classés unknown (TikTok webcast, YouTube, Facebook)."""
from __future__ import annotations

import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
WEBCAST_URL = "https://webcast.tiktok.com/webcast/room/info_by_user/?aid=1988&unique_id="
ENDPOINT_DEFAULT = "https://pass50.store/api/live-radar-unknown-audit.php"


def fetch(url: str, headers: dict[str, str] | None = None, timeout: int = 12, data: bytes | None = None) -> tuple[int, str]:
    req_headers = {"User-Agent": UA, "Accept": "application/json,text/html,*/*;q=0.8"}
    if headers:
        req_headers.update(headers)
    request = urllib.request.Request(url, data=data, headers=req_headers, method="POST" if data else "GET")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8", "replace")
            return int(response.status), body
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace") if exc.fp else ""
        return int(exc.code), body


def parse_tiktok_webcast(payload: dict[str, Any], handle: str) -> dict[str, Any] | None:
    if int(payload.get("status_code") or 0) != 0:
        return None
    data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
    status = data.get("status")
    try:
        status_int = int(status)
    except (TypeError, ValueError):
        return None
    if status_int != 2:
        return None
    room_id = str(data.get("id_str") or data.get("id") or "").strip()
    if not re.match(r"^[1-9]\d{5,}$", room_id):
        return None
    owner = data.get("owner") if isinstance(data.get("owner"), dict) else {}
    display = str(owner.get("display_id") or owner.get("unique_id") or "").strip().lstrip("@")
    expected = handle.strip().lstrip("@")
    if expected and display and display.lower() != expected.lower():
        return None
    started = None
    create_time = int(data.get("create_time") or 0)
    if create_time > 1577836800:
        from datetime import datetime, timezone
        started = datetime.fromtimestamp(create_time, tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    viewers = data.get("user_count")
    return {
        "roomId": room_id,
        "title": str(data.get("title") or "").strip(),
        "viewers": int(viewers) if isinstance(viewers, int) else None,
        "startedAt": started,
        "handle": display or expected,
    }


def parse_youtube_live_html(html: str) -> dict[str, Any] | None:
    if not re.search(r'"isLiveNow"\s*:\s*true', html, re.I):
        return None
    video_id = ""
    match = re.search(r'"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"', html)
    if match:
        video_id = match.group(1)
    if not video_id:
        match = re.search(r"/watch\?v=([A-Za-z0-9_-]{6,})", html)
        if match:
            video_id = match.group(1)
    if not video_id:
        return None
    title = ""
    title_match = re.search(r"<title>(.*?)</title>", html, re.I | re.S)
    if title_match:
        title = re.sub(r"\s*-\s*YouTube\s*$", "", re.sub(r"<[^>]+>", "", title_match.group(1))).strip()
    return {"videoId": video_id, "title": title}


def parse_facebook_live_html(html: str, fallback_url: str) -> dict[str, Any] | None:
    if re.search(r"captcha|verify to continue|checkpoint", html, re.I):
        return None
    if not re.search(r'"(?:is_live_streaming|isLiveStreaming)"\s*:\s*true', html, re.I):
        return None
    video_id = ""
    match = re.search(r'"(?:video_id|videoId)"\s*:\s*"(\d{5,})"', html)
    if match:
        video_id = match.group(1)
    url = fallback_url
    if video_id and "/videos/" not in fallback_url:
        base = re.sub(r"/live/?$", "", fallback_url.rstrip("/"))
        url = f"{base}/videos/{video_id}"
    return {"url": url, "videoId": video_id}


def probe_source(source: dict[str, Any]) -> dict[str, Any] | None:
    platform = str(source.get("platform") or "")
    handle = str(source.get("handle") or "")
    if platform == "TikTok" and handle:
        url = WEBCAST_URL + urllib.parse.quote(handle)
        status, body = fetch(
            url,
            headers={
                "Referer": f"https://www.tiktok.com/@{handle}",
                "Origin": "https://www.tiktok.com",
            },
        )
        if status != 200:
            return None
        try:
            payload = json.loads(body)
        except json.JSONDecodeError:
            return None
        parsed = parse_tiktok_webcast(payload, handle)
        if not parsed:
            return None
        return {
            "profileId": source["profileId"],
            "platform": "TikTok",
            **parsed,
        }
    if platform == "YouTube":
        live_url = str(source.get("liveUrl") or source.get("url") or "")
        if live_url and "/live" not in live_url:
            live_url = live_url.rstrip("/") + "/live"
        if not live_url:
            return None
        status, body = fetch(live_url, headers={"Accept": "text/html,*/*;q=0.8"})
        if status != 200:
            return None
        parsed = parse_youtube_live_html(body)
        if not parsed:
            return None
        return {"profileId": source["profileId"], "platform": "YouTube", **parsed}
    if platform == "Facebook":
        live_url = str(source.get("liveUrl") or source.get("url") or "")
        if not live_url:
            return None
        status, body = fetch(live_url, headers={"Accept": "text/html,*/*;q=0.8"})
        if status != 200:
            return None
        parsed = parse_facebook_live_html(body, live_url)
        if not parsed:
            return None
        return {"profileId": source["profileId"], "platform": "Facebook", **parsed}
    return None


def run(endpoint: str, secret: str) -> dict[str, Any]:
    status, body = fetch(f"{endpoint}?t=1", headers={"X-PASS50-CRON-SECRET": secret, "Accept": "application/json"})
    if status != 200:
        raise SystemExit(f"GET audit refusé HTTP {status}: {body[:300]}")
    listing = json.loads(body)
    if not listing.get("ok"):
        raise SystemExit(f"GET audit invalide: {body[:300]}")
    if not listing.get("enabled", True):
        print("Audit unknown désactivé — arrêt demandé.")
        return {"ok": True, "enabled": False, "lives": [], "posted": None}
    unknowns = listing.get("unknowns") or []
    lives: list[dict[str, Any]] = []
    with ThreadPoolExecutor(max_workers=8) as pool:
        futures = {pool.submit(probe_source, source): source for source in unknowns}
        for future in as_completed(futures):
            result = future.result()
            if result:
                lives.append(result)
    print(f"Unknown sondés : {len(unknowns)} · vraiment en live : {len(lives)}")
    for live in lives:
        print(f"- {live['platform']} {live['profileId']} {live.get('title') or live.get('roomId') or live.get('videoId')}")
    posted = None
    if lives:
        payload = json.dumps({"lives": lives}).encode("utf-8")
        status, body = fetch(
            endpoint,
            headers={
                "X-PASS50-CRON-SECRET": secret,
                "Accept": "application/json",
                "Content-Type": "application/json",
            },
            data=payload,
            timeout=45,
        )
        if status != 200:
            raise SystemExit(f"POST audit refusé HTTP {status}: {body[:400]}")
        posted = json.loads(body)
        print(
            "Publiés : {published} · ajoutés P0 : {added}".format(
                published=posted.get("published"),
                added=len(posted.get("added") or []),
            )
        )
    return {"ok": True, "enabled": True, "lives": lives, "posted": posted}


def main() -> None:
    secret = os.environ.get("PASS50_METRICS_CRON_SECRET") or os.environ.get("CRON_SECRET") or ""
    if len(secret) < 32:
        raise SystemExit("PASS50_METRICS_CRON_SECRET manquant.")
    endpoint = os.environ.get("UNKNOWN_AUDIT_ENDPOINT") or ENDPOINT_DEFAULT
    run(endpoint, secret)


if __name__ == "__main__":
    main()
