#!/usr/bin/env python3
"""Télécharge un MP4 depuis une URL et l'intègre au kit promo."""

from __future__ import annotations

import argparse
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
INGEST = ROOT / "tools" / "ingest_user_media.py"


def _filename_from_url(url: str, track: str | None) -> str:
    path = urlparse(url).path
    name = Path(path).name if path else "upload.mp4"
    if not Path(name).suffix:
        name = f"{track or 'upload'}.mp4"
    if track and not name.startswith(track):
        ext = Path(name).suffix or ".mp4"
        name = f"{track}{ext}"
    return name


def fetch(url: str, track: str | None = None, capture: bool = False) -> Path:
    with tempfile.TemporaryDirectory() as tmp:
        dest = Path(tmp) / _filename_from_url(url, track)
        print(f"Downloading {url} …")
        # curl suit redirects · large files OK
        subprocess.run(
            ["curl", "-fsSL", "-o", str(dest), url],
            check=True,
        )
        if dest.stat().st_size < 1000:
            raise RuntimeError("Fichier trop petit — vérifie que le lien est un téléchargement direct")
        cmd = [sys.executable, str(INGEST), "--file", str(dest)]
        if track:
            cmd.extend(["--track", track])
        if capture:
            cmd.append("--capture")
        subprocess.run(cmd, check=True)
        print("OK — fichier intégré dans pass50/promo/tiktok/assets/")
        return dest


def main() -> None:
    parser = argparse.ArgumentParser(description="Fetch MP4 from URL into promo kit")
    parser.add_argument("url", help="Lien HTTPS direct (WeTransfer, Dropbox, etc.)")
    parser.add_argument(
        "--track",
        help="01-live-liens-verifies | 02-anti-faux-comptes | 03-classement-2h",
    )
    parser.add_argument("--capture", action="store_true")
    args = parser.parse_args()
    fetch(args.url, args.track, args.capture)


if __name__ == "__main__":
    main()
