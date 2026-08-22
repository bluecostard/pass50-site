#!/usr/bin/env python3
"""Publie tous les lives P0 confirmés depuis GitHub (TikTok webcast + YouTube).

IONOS rate souvent `tiktok_embed_uninformative` alors que
webcast.tiktok.com/webcast/room/info_by_user (depuis GitHub) voit status=2.
Le POST réutilise live-radar-unknown-audit.php, qui stocke déjà les P0
sans les recopier dans la watchlist dynamique.
"""
from __future__ import annotations

import json
import os
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
sys.path.insert(0, str(SCRIPT_DIR))

from live_radar_unknown_audit import ENDPOINT_DEFAULT, fetch, probe_source  # noqa: E402

SOURCE_PHP = ROOT / "api" / "live-radar-v4-source.php"
ROTATION_LIMIT = 200


def load_p0_tiktok_sources(source_php: str | None = None) -> list[dict[str, str]]:
    text = source_php if source_php is not None else SOURCE_PHP.read_text(encoding="utf-8")
    if "const P50_LIVE_V4_P0_TIKTOK = [" not in text:
        raise ValueError("P50_LIVE_V4_P0_TIKTOK introuvable")
    block = text.split("const P50_LIVE_V4_P0_TIKTOK = [", 1)[1].split("];", 1)[0]
    profile_ids = re.findall(r"'([^']+)'", block)
    overrides: dict[str, str] = {}
    for match in re.finditer(
        r"'([^']+)\|tiktok'\s*=>\s*'https://(?:www\.)?tiktok\.com/@([^'?]+)'",
        text,
        flags=re.I,
    ):
        overrides[match.group(1)] = match.group(2).strip().lstrip("@")
    sources: list[dict[str, str]] = []
    seen_handles: set[str] = set()
    missing: list[str] = []
    for profile_id in profile_ids:
        handle = overrides.get(profile_id, "")
        if not handle:
            missing.append(profile_id)
            continue
        key = handle.lower()
        if key in seen_handles:
            continue
        seen_handles.add(key)
        sources.append({"profileId": profile_id, "platform": "TikTok", "handle": handle})
    if missing:
        print("P0 TikTok sans handle : " + ", ".join(missing), file=sys.stderr)
    for extra in load_declared_p0_tiktok_sources():
        key = extra["handle"].lower()
        if key in seen_handles:
            continue
        seen_handles.add(key)
        sources.append(extra)
    return sources


def _tiktok_handle_from_text(text: str) -> str:
    match = re.search(r"tiktok\.com/@([^/?#'\"\\]+)", text, flags=re.I)
    return match.group(1).strip().lstrip("@") if match else ""


def load_declared_p0_tiktok_sources() -> list[dict[str, str]]:
    sources: list[dict[str, str]] = []
    seen: set[str] = set()

    def add(profile_id: str, handle: str) -> None:
        handle = str(handle or "").strip().lstrip("@")
        profile_id = str(profile_id or "").strip()
        key = handle.lower()
        if not profile_id or not handle or key in seen:
            return
        seen.add(key)
        sources.append({"profileId": profile_id, "platform": "TikTok", "handle": handle})

    for path in sorted(ROOT.glob("profile-*.js")):
        text = path.read_text(encoding="utf-8")
        if "verificationPriority:'P0'" not in text and 'verificationPriority:"P0"' not in text:
            continue
        pid = re.search(r"const PROFILE_ID='([^']+)'", text)
        add(pid.group(1) if pid else "", _tiktok_handle_from_text(text))
    census_path = ROOT / "pass50_nouveaux_candidats_90_v19.json"
    if census_path.exists():
        try:
            census = json.loads(census_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            census = []
        for row in census if isinstance(census, list) else []:
            if str(row.get("verification_priority") or "").upper() != "P0":
                continue
            socials = row.get("official_socials") if isinstance(row.get("official_socials"), dict) else {}
            add(str(row.get("id") or ""), _tiktok_handle_from_text(str(socials.get("TikTok") or "")))
    return sources


def load_p0_youtube_sources(source_php: str | None = None) -> list[dict[str, str]]:
    text = source_php if source_php is not None else SOURCE_PHP.read_text(encoding="utf-8")
    if "const P50_LIVE_V4_P0_YOUTUBE = [" not in text:
        return []
    block = text.split("const P50_LIVE_V4_P0_YOUTUBE = [", 1)[1].split("];", 1)[0]
    profile_ids = re.findall(r"'([^']+)'", block)
    overrides: dict[str, str] = {}
    for match in re.finditer(
        r"'([^']+)\|youtube'\s*=>\s*'(https://(?:www\.)?youtube\.com/[^']+)'",
        text,
        flags=re.I,
    ):
        overrides[match.group(1)] = match.group(2).strip()
    sources: list[dict[str, str]] = []
    seen: set[str] = set()
    for profile_id in profile_ids:
        url = overrides.get(profile_id, "")
        if not url:
            continue
        key = url.lower().rstrip("/")
        if key in seen:
            continue
        seen.add(key)
        live_url = url if "/live" in url else url.rstrip("/") + "/live"
        sources.append(
            {
                "profileId": profile_id,
                "platform": "YouTube",
                "url": url,
                "liveUrl": live_url,
            }
        )
    return sources


def load_p0_sources(source_php: str | None = None) -> list[dict[str, str]]:
    text = source_php if source_php is not None else SOURCE_PHP.read_text(encoding="utf-8")
    return load_p0_tiktok_sources(text) + load_p0_youtube_sources(text)


def _source_key(row: dict[str, Any]) -> tuple[str, str]:
    return (str(row.get("profileId") or "").lower(), str(row.get("platform") or "").lower())


def source_from_audit_row(row: dict[str, Any]) -> dict[str, str] | None:
    profile_id = str(row.get("profileId") or row.get("profile_id") or "").strip()
    platform = str(row.get("platform") or "").strip()
    if not profile_id or not platform:
        return None
    if platform == "TikTok":
        handle = str(row.get("handle") or "").strip().lstrip("@")
        if not handle:
            return None
        return {"profileId": profile_id, "platform": "TikTok", "handle": handle}
    if platform == "YouTube":
        url = str(row.get("url") or "").strip()
        live_url = str(row.get("liveUrl") or "").strip()
        if not url and not live_url:
            return None
        if not live_url:
            live_url = url if "/live" in url else url.rstrip("/") + "/live"
        return {"profileId": profile_id, "platform": "YouTube", "url": url or live_url, "liveUrl": live_url}
    if platform == "Facebook":
        url = str(row.get("url") or row.get("liveUrl") or "").strip()
        if not url:
            return None
        return {"profileId": profile_id, "platform": "Facebook", "url": url, "liveUrl": str(row.get("liveUrl") or url)}
    return None


def load_audit_listing(endpoint: str, secret: str, limit: int = ROTATION_LIMIT) -> dict[str, Any]:
    sep = "&" if "?" in endpoint else "?"
    status, body = fetch(
        f"{endpoint}{sep}limit={limit}&t=1",
        headers={"X-PASS50-CRON-SECRET": secret, "Accept": "application/json"},
        timeout=30,
    )
    if status != 200:
        print(f"GET rotation HTTP {status}: {body[:200]}", file=sys.stderr)
        return {}
    try:
        listing = json.loads(body)
    except json.JSONDecodeError:
        print("GET rotation JSON invalide", file=sys.stderr)
        return {}
    return listing if isinstance(listing, dict) else {}


def merge_github_sources(
    seed: list[dict[str, str]],
    listing: dict[str, Any],
) -> list[dict[str, str]]:
    merged: list[dict[str, str]] = []
    seen: set[tuple[str, str]] = set()
    for row in seed:
        key = _source_key(row)
        if key in seen:
            continue
        seen.add(key)
        merged.append(row)
    for raw in list(listing.get("p0") or []) + list(listing.get("unknowns") or []):
        if not isinstance(raw, dict):
            continue
        source = source_from_audit_row(raw)
        if source is None:
            continue
        key = _source_key(source)
        if key in seen:
            continue
        seen.add(key)
        merged.append(source)
    return merged


def probe_p0_lives(sources: list[dict[str, str]] | None = None) -> list[dict[str, Any]]:
    sources = sources if sources is not None else load_p0_sources()
    lives: list[dict[str, Any]] = []
    workers = min(16, max(1, len(sources)))
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(probe_source, source): source for source in sources}
        for future in as_completed(futures):
            result_live = future.result()
            if result_live:
                lives.append(result_live)
    lives.sort(key=lambda item: (str(item.get("profileId") or ""), str(item.get("handle") or "")))
    return lives


def _decode_posted(status: int, body: str) -> dict[str, Any] | None:
    if status != 200:
        return None
    try:
        posted = json.loads(body)
    except json.JSONDecodeError:
        return None
    if not isinstance(posted, dict) or not posted.get("ok"):
        return None
    return posted


def _post_once(endpoint: str, secret: str, lives: list[dict[str, Any]]) -> tuple[int, str]:
    payload = json.dumps({"lives": lives}).encode("utf-8")
    return fetch(
        endpoint,
        headers={
            "X-PASS50-CRON-SECRET": secret,
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
        data=payload,
        timeout=45,
    )


def post_lives(endpoint: str, secret: str, lives: list[dict[str, Any]]) -> dict[str, Any]:
    last_error = ""
    for attempt in range(1, 4):
        status, body = _post_once(endpoint, secret, lives)
        posted = _decode_posted(status, body)
        if posted is not None:
            return posted
        last_error = f"HTTP {status}: {body[:400]}"
        print(f"POST lot tentative {attempt}/3 échouée ({status})", file=sys.stderr)
        if attempt < 3:
            time.sleep(min(8, 2 * attempt))
    stored: list[dict[str, Any]] = []
    skipped: list[dict[str, Any]] = []
    published = 0
    for live in lives:
        posted = None
        for attempt in range(1, 3):
            status, body = _post_once(endpoint, secret, [live])
            posted = _decode_posted(status, body)
            if posted is not None:
                break
            last_error = f"HTTP {status}: {body[:400]}"
            time.sleep(1)
        if posted is None:
            skipped.append(
                {
                    "profileId": live.get("profileId"),
                    "platform": live.get("platform"),
                    "error": "post_failed",
                    "detail": last_error[:180],
                }
            )
            continue
        published += int(posted.get("published") or 0)
        stored.extend(posted.get("stored") or [])
        skipped.extend(posted.get("skipped") or [])
    if published <= 0:
        raise SystemExit(f"POST P0 webcast refusé: {last_error}")
    return {"ok": True, "published": published, "stored": stored, "skipped": skipped, "added": []}


def run(endpoint: str, secret: str, dry_run: bool = False) -> dict[str, Any]:
    seed = load_p0_sources()
    listing: dict[str, Any] = {}
    if secret and not dry_run:
        listing = load_audit_listing(endpoint, secret, ROTATION_LIMIT)
    sources = merge_github_sources(seed, listing)
    lives = probe_p0_lives(sources)
    print(
        "GitHub sondés : {total} (P0 {seed} · rotation {extra}) · vraiment en live : {lives}".format(
            total=len(sources),
            seed=len(seed),
            extra=max(0, len(sources) - len(seed)),
            lives=len(lives),
        )
    )
    for live in lives:
        handle = live.get("handle") or ""
        extra = f" @{handle}" if handle else ""
        viewers = live.get("viewers")
        view = f" · {viewers} viewers" if viewers not in (None, "") else ""
        print(f"- {live.get('profileId')}{extra} — {live.get('title') or live.get('roomId')}{view}")
    posted = None
    if lives and not dry_run:
        posted = post_lives(endpoint, secret, lives)
        print(
            "Publiés : {published} · ignorés : {skipped}".format(
                published=posted.get("published"),
                skipped=len(posted.get("skipped") or []),
            )
        )
        for row in posted.get("stored") or []:
            print(f"  stocké {row.get('platform')} `{row.get('profileId')}`")
        for row in posted.get("skipped") or []:
            print(f"  ignoré {row.get('platform')} `{row.get('profileId')}` ({row.get('error')})")
    elif lives:
        print("Dry-run : aucune publication.")
    return {"ok": True, "sources": sources, "lives": lives, "posted": posted}


def main() -> None:
    dry_run = "--dry-run" in sys.argv
    secret = os.environ.get("PASS50_METRICS_CRON_SECRET") or os.environ.get("CRON_SECRET") or ""
    if not dry_run and len(secret) < 32:
        raise SystemExit("PASS50_METRICS_CRON_SECRET manquant.")
    endpoint = os.environ.get("UNKNOWN_AUDIT_ENDPOINT") or ENDPOINT_DEFAULT
    run(endpoint, secret, dry_run=dry_run)


if __name__ == "__main__":
    main()
