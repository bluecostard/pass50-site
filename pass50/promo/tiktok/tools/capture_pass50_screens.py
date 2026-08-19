#!/usr/bin/env python3
"""Captures pass50.store pour montage promo (mobile 9:16)."""

from __future__ import annotations

import argparse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "captures"


def capture(url: str = "https://pass50.store") -> list[Path]:
    from playwright.sync_api import sync_playwright

    OUT.mkdir(parents=True, exist_ok=True)
    paths: list[Path] = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 390, "height": 844}, device_scale_factor=2)
        page.goto(url, wait_until="domcontentloaded", timeout=90000)
        page.wait_for_timeout(4000)

        shots = [
            ("pass50-home-top.png", 0),
            ("pass50-ranking-scroll.png", 500),
            ("pass50-ranking-deep.png", 1100),
        ]
        for name, scroll_y in shots:
            page.evaluate(f"window.scrollTo(0, {scroll_y})")
            page.wait_for_timeout(800)
            dest = OUT / name
            page.screenshot(path=str(dest), full_page=False)
            paths.append(dest)
            print(f"Captured {dest}")

        browser.close()
    return paths


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default="https://pass50.store")
    args = parser.parse_args()
    capture(args.url)


if __name__ == "__main__":
    main()
