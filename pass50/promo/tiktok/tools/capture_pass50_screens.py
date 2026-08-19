#!/usr/bin/env python3
"""Captures pass50.store — plans multiples pour montage pro (mobile 9:16)."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "captures"
MANIFEST = OUT / "shots-manifest.json"


def _shot(page, name: str, *, selector: str | None = None, scroll_y: int | None = None) -> str:
    if scroll_y is not None:
        page.evaluate(f"window.scrollTo({{top: {scroll_y}, behavior: 'instant'}})")
        page.wait_for_timeout(600)
    dest = OUT / name
    if selector:
        loc = page.locator(selector).first
        loc.wait_for(state="visible", timeout=15000)
        loc.screenshot(path=str(dest))
    else:
        page.screenshot(path=str(dest), full_page=False)
    print(f"  ✓ {name}")
    return name


def capture(url: str = "https://pass50.store") -> dict:
    from playwright.sync_api import sync_playwright

    OUT.mkdir(parents=True, exist_ok=True)
    captured: list[str] = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 390, "height": 844}, device_scale_factor=3)
        page.goto(url, wait_until="networkidle", timeout=120000)
        page.wait_for_timeout(3500)

        captured.append(_shot(page, "01-header-brand.png", selector=".brand"))
        captured.append(_shot(page, "02-hero-buzz.png", selector=".hero"))
        captured.append(_shot(page, "03-filters-period.png", selector=".filters"))

        # Header LIVE (toujours visuel même sans direct actif)
        try:
            captured.append(_shot(page, "03b-live-header.png", selector="#liveBtn"))
        except Exception as e:
            print(f"  ⚠ live header: {e}")

        cards = page.locator(".rank-card")
        n_cards = cards.count()
        for i in range(min(4, n_cards)):
            fname = f"04-rank-card-{i + 1}.png"
            cards.nth(i).screenshot(path=str(OUT / fname))
            captured.append(fname)
            print(f"  ✓ {fname}")
        if n_cards == 0:
            captured.append(_shot(page, "04-rank-card-1.png", scroll_y=400))

        # Fiche influenceur (badges, liens vérifiés)
        try:
            cards.first.click(timeout=5000)
            page.wait_for_timeout(1500)
            page.locator("#profileModal.show .modal-box").wait_for(state="visible", timeout=8000)
            captured.append(_shot(page, "05-profile-fiche.png", selector="#profileModal.show .modal-box"))
            page.locator('[data-close="profileModal"]').click(force=True, timeout=3000)
            page.wait_for_timeout(800)
        except Exception as e:
            print(f"  ⚠ profile modal: {e}")
            page.keyboard.press("Escape")
            page.wait_for_timeout(400)

        page.evaluate("document.querySelector('#top10')?.scrollIntoView({block:'start'})")
        page.wait_for_timeout(700)
        captured.append(_shot(page, "08-top10-section.png", selector="#top10"))

        top10_cards = page.locator("#top10Grid .mini, #top10Grid > *")
        for i in range(min(4, top10_cards.count())):
            try:
                top10_cards.nth(i).screenshot(path=str(OUT / f"09-top10-mini-{i + 1}.png"))
                captured.append(f"09-top10-mini-{i + 1}.png")
                print(f"  ✓ 09-top10-mini-{i + 1}.png")
            except Exception:
                pass

        page.evaluate("document.querySelector('#tendance')?.scrollIntoView({block:'start'})")
        page.wait_for_timeout(700)
        captured.append(_shot(page, "10-tendance.png", selector="#tendance"))

        try:
            page.locator("#top50Btn").click(timeout=5000)
            page.wait_for_timeout(1500)
            page.locator("#top50Modal.show .modal-box").wait_for(state="visible", timeout=8000)
            captured.append(_shot(page, "11-modal-top50.png", selector="#top50Modal.show .modal-box"))
            # Rang 1–3 dans le modal
            for i in range(min(3, page.locator("#top50Modal .top50-row").count())):
                row = page.locator("#top50Modal .top50-row").nth(i)
                fname = f"11b-top50-row-{i + 1}.png"
                row.screenshot(path=str(OUT / fname))
                captured.append(fname)
                print(f"  ✓ {fname}")
            page.locator('[data-close="top50Modal"]').click(force=True, timeout=3000)
            page.wait_for_timeout(800)
        except Exception as e:
            print(f"  ⚠ top50 modal: {e}")
            page.keyboard.press("Escape")
            page.wait_for_timeout(400)

        try:
            page.locator("#liveBtn").click(timeout=5000)
            page.wait_for_timeout(1200)
            live_body = page.locator("#liveBody")
            if live_body.locator(".signal, .live-card, [data-live-profile]").count() > 0:
                captured.append(_shot(page, "12-modal-live.png", selector="#liveModal.show .modal-box"))
            else:
                page.keyboard.press("Escape")
                page.wait_for_timeout(400)
                print("  ⚠ live modal empty — skipped")
        except Exception as e:
            print(f"  ⚠ live modal: {e}")

        for j, y in enumerate([900, 1400, 2000, 2600], start=13):
            captured.append(_shot(page, f"{j:02d}-scroll-{y}.png", scroll_y=y))

        browser.close()

    payload = {
        "version": "CAPTURES-V3",
        "url": url,
        "count": len(captured),
        "files": captured,
        "sequences": {
            "02-anti-faux-comptes": [
                "02-hero-buzz.png",
                "04-rank-card-1.png",
                "05-profile-fiche.png",
                "04-rank-card-2.png",
                "08-top10-section.png",
                "09-top10-mini-1.png",
                "09-top10-mini-2.png",
                "11-modal-top50.png",
                "11b-top50-row-1.png",
                "11b-top50-row-2.png",
                "10-tendance.png",
                "04-rank-card-3.png",
                "01-header-brand.png",
            ],
            "03-classement-2h": [
                "03-filters-period.png",
                "04-rank-card-1.png",
                "04-rank-card-2.png",
                "09-top10-mini-1.png",
                "09-top10-mini-2.png",
                "09-top10-mini-3.png",
                "08-top10-section.png",
                "11-modal-top50.png",
                "11b-top50-row-1.png",
                "13-scroll-900.png",
                "14-scroll-1400.png",
                "15-scroll-2000.png",
                "16-scroll-2600.png",
                "02-hero-buzz.png",
            ],
        },
    }
    MANIFEST.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Manifest → {MANIFEST} ({len(captured)} shots)")
    return payload


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default="https://pass50.store")
    args = parser.parse_args()
    capture(args.url)


if __name__ == "__main__":
    main()
