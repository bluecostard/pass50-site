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
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
WEBCAST_URL = "https://webcast.tiktok.com/webcast/room/info_by_user/?aid=1988&unique_id="
ROOM_API_URL = "https://www.tiktok.com/api-live/user/room/?aid=1988&sourceType=54&uniqueId="
ENDPOINT_DEFAULT = "https://pass50.store/api/live-radar-unknown-audit.php"
DISCUSSION_PATH = Path("pass50/discussions/radar-unknown-audit.md")
LATEST_PATH = Path("pass50/discussions/radar-unknown-audit-latest.json")
JOURNAL_MARK = "<!-- JOURNAL:BEGIN -->"
MAX_JOURNAL_ENTRIES = 40
DISCUSSION_HEADER = """# Discussion PASS50 — Audit unknown radar

Contrôle régulier des lives classés `unknown`. Chaque passage (toutes les 3 h) s’écrit **en haut** de ce journal.

- Outil TikTok : `webcast.tiktok.com/webcast/room/info_by_user`
- YouTube : `isLiveNow` · Facebook : signal live public si la page n’est pas bloquée
- Les salles terminées ne sont pas poussées
- Un compte déjà en P0 n’est pas recopié dans la liste

Pour arrêter la boucle : le dire dans le chat PASS50.

"""


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
        started = datetime.fromtimestamp(create_time, tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    viewers = data.get("user_count")
    return {
        "roomId": room_id,
        "title": str(data.get("title") or "").strip(),
        "viewers": int(viewers) if isinstance(viewers, int) else None,
        "startedAt": started,
        "handle": display or expected,
    }


def parse_tiktok_room_api(payload: dict[str, Any], handle: str) -> dict[str, Any] | None:
    data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
    user = data.get("user") if isinstance(data.get("user"), dict) else {}
    live_room = data.get("liveRoom") if isinstance(data.get("liveRoom"), dict) else {}
    unique = str(user.get("uniqueId") or "").strip().lstrip("@")
    expected = handle.strip().lstrip("@")
    if expected and unique and unique.lower() != expected.lower():
        return None
    status = user.get("status")
    if status is None:
        status = live_room.get("status")
    try:
        status_int = int(status)
    except (TypeError, ValueError):
        return None
    if status_int != 2:
        return None
    room_id = str(user.get("roomId") or live_room.get("roomId") or live_room.get("id") or "").strip()
    if not re.match(r"^[1-9]\d{5,}$", room_id):
        return None
    stats = live_room.get("liveRoomStats") if isinstance(live_room.get("liveRoomStats"), dict) else {}
    viewers = stats.get("userCount")
    title = str(live_room.get("title") or user.get("nickname") or "").strip()
    return {
        "roomId": room_id,
        "title": title,
        "viewers": int(viewers) if isinstance(viewers, int) and viewers > 0 else None,
        "startedAt": None,
        "handle": unique or expected,
    }


def probe_tiktok_handle(handle: str) -> dict[str, Any] | None:
    handle = str(handle or "").strip().lstrip("@")
    if not handle:
        return None
    headers = {
        "Referer": f"https://www.tiktok.com/@{handle}",
        "Origin": "https://www.tiktok.com",
    }
    status, body = fetch(WEBCAST_URL + urllib.parse.quote(handle), headers=headers)
    if status == 200:
        try:
            parsed = parse_tiktok_webcast(json.loads(body), handle)
        except json.JSONDecodeError:
            parsed = None
        if parsed:
            return parsed
    status, body = fetch(ROOM_API_URL + urllib.parse.quote(handle), headers=headers)
    if status != 200:
        return None
    try:
        return parse_tiktok_room_api(json.loads(body), handle)
    except json.JSONDecodeError:
        return None


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
        parsed = probe_tiktok_handle(handle)
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


def utc_now_label() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")


def format_discussion_entry(result: dict[str, Any], when: str | None = None) -> str:
    when = when or utc_now_label()
    unknowns = int(result.get("unknownCount") or 0)
    lives = result.get("lives") or []
    posted = result.get("posted") if isinstance(result.get("posted"), dict) else {}
    added = posted.get("added") or []
    stored = posted.get("stored") or []
    skipped = posted.get("skipped") or []
    enabled = result.get("enabled", True)
    error = str(result.get("error") or "")
    lines = [f"### {when}", ""]
    if not enabled:
        lines.append("Boucle **arrêtée** (`live_radar_v4_unknown_audit_enabled=false`).")
        lines.append("")
        return "\n".join(lines)
    if error:
        lines.append(f"Erreur : `{error}`")
        lines.append("")
        return "\n".join(lines)
    lines.append(f"- Unknown sondés : **{unknowns}**")
    lines.append(f"- Vraiment en live : **{len(lives)}**")
    lines.append(f"- Publiés radar : **{int(posted.get('published') or 0)}**")
    lines.append(f"- Ajoutés P0 : **{len(added)}**")
    if lives:
        lines.append("")
        lines.append("Vraiment en live :")
        for live in lives:
            label = live.get("title") or live.get("roomId") or live.get("videoId") or ""
            handle = live.get("handle") or ""
            extra = f" @{handle}" if handle else ""
            viewers = live.get("viewers")
            view = f" · {viewers} viewers" if viewers not in (None, "") else ""
            lines.append(f"- {live.get('platform')} `{live.get('profileId')}`{extra} — {label}{view}")
    else:
        lines.append("- Aucun unknown réellement en live à ce passage.")
    if added:
        lines.append("")
        lines.append("Nouveaux P0 :")
        for row in added:
            lines.append(f"- {row.get('platform')} `{row.get('profileId')}`")
    if stored and not lives:
        lines.append("")
        lines.append("Publiés : " + ", ".join(f"{row.get('platform')} `{row.get('profileId')}`" for row in stored))
    if skipped:
        lines.append("")
        lines.append(f"Ignorés : {len(skipped)}")
    lines.append("")
    return "\n".join(lines)


def split_journal_entries(body: str) -> list[str]:
    chunks = re.split(r"(?m)^### ", body.strip())
    entries = []
    for chunk in chunks:
        chunk = chunk.strip()
        if not chunk:
            continue
        entries.append("### " + chunk + "\n")
    return entries


def write_discussion_log(result: dict[str, Any], discussion_path: Path = DISCUSSION_PATH, latest_path: Path = LATEST_PATH) -> None:
    discussion_path.parent.mkdir(parents=True, exist_ok=True)
    entry = format_discussion_entry(result)
    existing = discussion_path.read_text(encoding="utf-8") if discussion_path.is_file() else DISCUSSION_HEADER + JOURNAL_MARK + "\n"
    if JOURNAL_MARK not in existing:
        existing = DISCUSSION_HEADER + JOURNAL_MARK + "\n" + existing
    prefix, remainder = existing.split(JOURNAL_MARK, 1)
    old_entries = split_journal_entries(remainder)
    entries = [entry] + old_entries
    entries = entries[:MAX_JOURNAL_ENTRIES]
    discussion_path.write_text(prefix + JOURNAL_MARK + "\n\n" + "\n".join(entries).rstrip() + "\n", encoding="utf-8")
    snapshot = {
        "at": utc_now_label(),
        "unknownCount": result.get("unknownCount"),
        "liveCount": len(result.get("lives") or []),
        "lives": result.get("lives") or [],
        "posted": result.get("posted"),
        "enabled": result.get("enabled", True),
        "error": result.get("error"),
    }
    latest_path.write_text(json.dumps(snapshot, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def run(endpoint: str, secret: str) -> dict[str, Any]:
    status, body = fetch(f"{endpoint}?t=1", headers={"X-PASS50-CRON-SECRET": secret, "Accept": "application/json"})
    if status != 200:
        result = {"ok": False, "enabled": True, "lives": [], "posted": None, "unknownCount": 0, "error": f"GET HTTP {status}"}
        write_discussion_log(result)
        raise SystemExit(f"GET audit refusé HTTP {status}: {body[:300]}")
    listing = json.loads(body)
    if not listing.get("ok"):
        result = {"ok": False, "enabled": True, "lives": [], "posted": None, "unknownCount": 0, "error": "GET invalide"}
        write_discussion_log(result)
        raise SystemExit(f"GET audit invalide: {body[:300]}")
    if not listing.get("enabled", True):
        result = {"ok": True, "enabled": False, "lives": [], "posted": None, "unknownCount": 0}
        write_discussion_log(result)
        print("Audit unknown désactivé — arrêt demandé.")
        return result
    unknowns = listing.get("unknowns") or []
    lives: list[dict[str, Any]] = []
    with ThreadPoolExecutor(max_workers=8) as pool:
        futures = {pool.submit(probe_source, source): source for source in unknowns}
        for future in as_completed(futures):
            result_live = future.result()
            if result_live:
                lives.append(result_live)
    lives.sort(key=lambda item: (str(item.get("platform") or ""), str(item.get("profileId") or "")))
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
            result = {"ok": False, "enabled": True, "lives": lives, "posted": None, "unknownCount": len(unknowns), "error": f"POST HTTP {status}"}
            write_discussion_log(result)
            raise SystemExit(f"POST audit refusé HTTP {status}: {body[:400]}")
        posted = json.loads(body)
        print(
            "Publiés : {published} · ajoutés P0 : {added}".format(
                published=posted.get("published"),
                added=len(posted.get("added") or []),
            )
        )
    result = {"ok": True, "enabled": True, "lives": lives, "posted": posted, "unknownCount": len(unknowns)}
    write_discussion_log(result)
    return result


def main() -> None:
    secret = os.environ.get("PASS50_METRICS_CRON_SECRET") or os.environ.get("CRON_SECRET") or ""
    if len(secret) < 32:
        raise SystemExit("PASS50_METRICS_CRON_SECRET manquant.")
    endpoint = os.environ.get("UNKNOWN_AUDIT_ENDPOINT") or ENDPOINT_DEFAULT
    run(endpoint, secret)


if __name__ == "__main__":
    main()
