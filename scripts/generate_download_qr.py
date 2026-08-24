#!/usr/bin/env python3
"""Génère le QR officiel du lien d'installation PASS50 (hors stores)."""
from pathlib import Path

import segno

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "assets"
QR_URL = "https://pass50.store/telecharger"
QR_ERROR = "h"
QR_SCALE = 12
QR_BORDER = 4
QR_DARK = "#050705"
QR_LIGHT = "#ffffff"


def make_qr():
    return segno.make(QR_URL, error=QR_ERROR)


def main() -> None:
    ASSETS.mkdir(parents=True, exist_ok=True)
    qr = make_qr()
    common = dict(scale=QR_SCALE, border=QR_BORDER, dark=QR_DARK, light=QR_LIGHT)
    qr.save(ASSETS / "qr-telecharger-pass50.svg", **common)
    qr.save(ASSETS / "qr-telecharger-pass50.png", kind="png", **common)
    print(f"QR {QR_URL} → {ASSETS / 'qr-telecharger-pass50.svg'}")
    print(f"QR {QR_URL} → {ASSETS / 'qr-telecharger-pass50.png'}")


if __name__ == "__main__":
    main()
