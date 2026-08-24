#!/usr/bin/env python3
"""Test Live Scout sur 12 comptes officiels vérifiés PASS50 (hors intégration)."""

from __future__ import annotations

import json
import sys
import time
from pathlib import Path

# Réutilise le moteur standalone
sys.path.insert(0, str(Path(__file__).resolve().parent))
from server import probe_url  # noqa: E402

# 12 noms + 1 lien officiel vérifié par profil (sources : fiches PASS50 / recensement).
TARGETS = [
    {"name": "Atoulé", "url": "https://www.tiktok.com/@atouleee.officiel"},
    {"name": "Dez Cocrane 225", "url": "https://www.tiktok.com/@dezcocrane.225"},
    {"name": "Ennemi des Djandjou", "url": "https://www.tiktok.com/@ennemidesdjandjou"},
    {"name": "Général Camille Makosso", "url": "https://www.tiktok.com/@generalmakossocamille79"},
    {"name": "Ismaël Aka", "url": "https://www.tiktok.com/@ismael.aka.ddr"},
    {"name": "Ivorian Kid", "url": "https://www.tiktok.com/@ivoriankid"},
    {"name": "Kim Makosso", "url": "https://www.tiktok.com/@kim.makosso_officielle"},
    {"name": "Lionel PCS", "url": "https://www.tiktok.com/@lionel_pcs"},
    {"name": "Lolo Beauté", "url": "https://www.instagram.com/lolobeaute_officiel/"},
    {"name": "Mélanie TMS", "url": "https://www.tiktok.com/@melanie.tms"},
    {"name": "Oustaz Diané", "url": "https://www.tiktok.com/@oustazdianeofficiel1"},
    {"name": "Yasmine Fofana", "url": "https://www.youtube.com/@YasmineAfrofoodie"},
]


def main() -> int:
    rows = []
    print(f"Live Scout — test de {len(TARGETS)} comptes officiels vérifiés\n")
    for i, target in enumerate(TARGETS, 1):
        print(f"[{i:02d}/{len(TARGETS)}] {target['name']} …", flush=True)
        started = time.time()
        result = probe_url(target["url"], target["name"])
        elapsed = time.time() - started
        if not result.get("ok"):
            row = {
                "name": target["name"],
                "url": target["url"],
                "state": "error",
                "confidence": 0,
                "platform": "—",
                "error": result.get("error"),
                "seconds": round(elapsed, 2),
            }
        else:
            hit = result["hits"][0]
            row = {
                "name": target["name"],
                "url": target["url"],
                "platform": hit.get("platform"),
                "state": hit.get("state"),
                "confidence": hit.get("confidence"),
                "title": hit.get("title"),
                "watchUrl": hit.get("url"),
                "viewers": hit.get("viewers"),
                "error": hit.get("error") or "",
                "seconds": round(elapsed, 2),
                "probes": hit.get("probes"),
            }
        rows.append(row)
        print(
            f"       → {row['platform']} · {row['state']} · confiance {row['confidence']}"
            + (f" · {row['error']}" if row.get("error") else "")
            + f" ({row['seconds']}s)",
            flush=True,
        )
        time.sleep(0.35)  # petite pause anti-burst

    summary = {"live": 0, "probable": 0, "offline": 0, "unknown": 0, "replay": 0, "error": 0}
    for row in rows:
        summary[row["state"]] = summary.get(row["state"], 0) + 1

    out = {
        "ok": True,
        "tool": "live-scout-standalone",
        "tested": len(rows),
        "summary": summary,
        "results": rows,
    }
    report = Path(__file__).resolve().parent / "test-15-officiels.json"
    report.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")

    print("\n=== RÉSUMÉ ===")
    for key, value in summary.items():
        if value:
            print(f"  {key}: {value}")
    print(f"\nRapport écrit : {report}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
