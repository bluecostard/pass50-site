#!/usr/bin/env python3
"""Publie les lives TikTok P0 confirmés par webcast GitHub.

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
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
sys.path.insert(0, str(SCRIPT_DIR))

from live_radar_unknown_audit import ENDPOINT_DEFAULT, fetch, probe_source  # noqa: E402

SOURCE_PHP = ROOT / "api" / "live-radar-v4-source.php"


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
    return sources


def probe_p0_lives(sources: list[dict[str, str]] | None = None) -> list[dict[str, Any]]:
    sources = sources if sources is not None else load_p0_tiktok_sources()
    lives: list[dict[str, Any]] = []
    workers = min(8, max(1, len(sources)))
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(probe_source, source): source for source in sources}
        for future in as_completed(futures):
            result_live = future.result()
            if result_live:
                lives.append(result_live)
    lives.sort(key=lambda item: (str(item.get("profileId") or ""), str(item.get("handle") or "")))
    return lives


def post_lives(endpoint: str, secret: str, lives: list[dict[str, Any]]) -> dict[str, Any]:
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
        raise SystemExit(f"POST P0 webcast refusé HTTP {status}: {body[:400]}")
    posted = json.loads(body)
    if not posted.get("ok"):
        raise SystemExit(f"POST P0 webcast invalide: {body[:400]}")
    return posted


def run(endpoint: str, secret: str, dry_run: bool = False) -> dict[str, Any]:
    sources = load_p0_tiktok_sources()
    lives = probe_p0_lives(sources)
    print(f"P0 TikTok sondés : {len(sources)} · vraiment en live : {len(lives)}")
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
