#!/usr/bin/env python3
"""Radar LIVE — boucle continue 1 Hz (autonomie 24/7 hors app).

RÈGLE FIGÉE PASS50_LIVE_RADAR_AUTONOMY_V1 :
- la détection tourne sur le serveur (GitHub Actions → sondes → POST IONOS) ;
- elle ne dépend jamais de l’app / onglet / owner connecté ;
- ce process ticke chaque seconde et tourne les sources P0 en rotation.

GitHub ne peut pas planifier plus fin que */5 ; chaque run boucle donc
~280 s à 1 Hz pour couvrir l’intervalle entre deux schedules.
"""
from __future__ import annotations

import argparse
import os
import sys
import time
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from live_radar_p0_webcast import (  # noqa: E402
    load_audit_listing,
    load_p0_sources,
    merge_github_sources,
    post_lives,
)
from live_radar_unknown_audit import ENDPOINT_DEFAULT, probe_source  # noqa: E402

AUTONOMY_REVISION = "PASS50_LIVE_RADAR_AUTONOMY_V1"
DEFAULT_SECONDS = 280
DEFAULT_TICK = 1.0
ROTATION_LIMIT = 200


def run_continuous(
    endpoint: str,
    secret: str,
    *,
    seconds: float = DEFAULT_SECONDS,
    tick: float = DEFAULT_TICK,
    dry_run: bool = False,
) -> dict[str, Any]:
    seed = load_p0_sources()
    listing: dict[str, Any] = {}
    if secret and not dry_run:
        try:
            listing = load_audit_listing(endpoint, secret, ROTATION_LIMIT)
        except Exception as exc:  # noqa: BLE001 — boucle non bloquante
            print(f"Listing audit non bloquant : {exc}", file=sys.stderr)
            listing = {}
    sources = merge_github_sources(seed, listing)
    if not sources:
        raise SystemExit("Aucune source P0 à sonder.")

    print(
        f"{AUTONOMY_REVISION} · {len(sources)} source(s) · tick {tick:g}s · durée {seconds:g}s · app non requise"
    )
    started = time.monotonic()
    deadline = started + max(1.0, float(seconds))
    index = 0
    probed = 0
    lives_found = 0
    published = 0
    errors = 0

    while time.monotonic() < deadline:
        source = sources[index % len(sources)]
        index += 1
        probed += 1
        tick_started = time.monotonic()
        try:
            live = probe_source(source)
        except Exception as exc:  # noqa: BLE001
            errors += 1
            print(f"tick erreur {source.get('profileId')}: {exc}", file=sys.stderr)
            live = None
        if live:
            lives_found += 1
            handle = live.get("handle") or ""
            extra = f" @{handle}" if handle else ""
            print(f"LIVE {live.get('profileId')}{extra} · {live.get('platform')}")
            if not dry_run:
                try:
                    posted = post_lives(endpoint, secret, [live])
                    published += int(posted.get("published") or 0)
                except Exception as exc:  # noqa: BLE001
                    errors += 1
                    print(f"POST tick échoué : {exc}", file=sys.stderr)

        elapsed = time.monotonic() - tick_started
        sleep_for = max(0.0, float(tick) - elapsed)
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            break
        if sleep_for > 0:
            time.sleep(min(sleep_for, remaining))

    summary = {
        "ok": True,
        "autonomy": AUTONOMY_REVISION,
        "requiresAppOpen": False,
        "tickSeconds": float(tick),
        "durationSeconds": round(time.monotonic() - started, 2),
        "sources": len(sources),
        "probed": probed,
        "livesFound": lives_found,
        "published": published,
        "errors": errors,
    }
    print(
        "Fin boucle : {probed} sondes · {lives} live(s) · {published} publié(s) · {errors} erreur(s) · {duration}s".format(
            probed=probed,
            lives=lives_found,
            published=published,
            errors=errors,
            duration=summary["durationSeconds"],
        )
    )
    return summary


def main() -> None:
    parser = argparse.ArgumentParser(description="Radar LIVE continuous 1 Hz autonomy loop")
    parser.add_argument("--seconds", type=float, default=DEFAULT_SECONDS)
    parser.add_argument("--tick", type=float, default=DEFAULT_TICK)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    secret = os.environ.get("PASS50_METRICS_CRON_SECRET") or os.environ.get("CRON_SECRET") or ""
    if not args.dry_run and len(secret) < 32:
        raise SystemExit("PASS50_METRICS_CRON_SECRET manquant.")
    endpoint = os.environ.get("UNKNOWN_AUDIT_ENDPOINT") or ENDPOINT_DEFAULT
    run_continuous(
        endpoint,
        secret,
        seconds=args.seconds,
        tick=args.tick,
        dry_run=args.dry_run,
    )


if __name__ == "__main__":
    main()
