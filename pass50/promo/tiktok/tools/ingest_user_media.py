#!/usr/bin/env python3
"""Importe un MP4/MOV (ou audio) utilisateur dans le kit promo."""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VOICE_DIR = ROOT / "assets" / "voice"
CAPTURE_DIR = ROOT / "assets" / "captures"
MANIFEST = VOICE_DIR / "manifest.json"

VIDEO_EXT = {".mp4", ".mov", ".webm", ".m4v"}
AUDIO_EXT = {".mp3", ".m4a", ".wav", ".aac", ".ogg"}


def _match_track(filename: str, manifest: dict) -> str | None:
    lower = filename.lower()
    for track in manifest.get("tracks", []):
        for expected in track.get("expectedFiles", []):
            if lower == expected.lower() or lower.startswith(track["id"]):
                return track["id"]
    return None


def extract_audio(video: Path, out: Path) -> None:
    subprocess.run(
        [
            "ffmpeg",
            "-y",
            "-i",
            str(video),
            "-vn",
            "-acodec",
            "libmp3lame",
            "-q:a",
            "2",
            str(out),
        ],
        check=True,
        capture_output=True,
    )


def ingest(src: Path, track_id: str | None, capture: bool) -> None:
    if not src.exists():
        raise FileNotFoundError(src)

    ext = src.suffix.lower()
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    track_id = track_id or _match_track(src.name, manifest)

    if capture or "capture" in src.name.lower() or "screen" in src.name.lower():
        dest_dir = CAPTURE_DIR
    else:
        dest_dir = VOICE_DIR

    dest_dir.mkdir(parents=True, exist_ok=True)

    if track_id:
        dest = dest_dir / f"{track_id}{ext}"
    else:
        dest = dest_dir / src.name

    shutil.copy2(src, dest)
    print(f"Copied → {dest}")

    if ext in VIDEO_EXT and dest_dir == VOICE_DIR:
        audio_out = dest_dir / f"{dest.stem}.mp3"
        extract_audio(dest, audio_out)
        print(f"Extracted audio → {audio_out}")

    if track_id:
        for track in manifest.get("tracks", []):
            if track["id"] == track_id:
                track["status"] = "received"
                track["receivedFile"] = str(dest.relative_to(ROOT))
        MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description="Ingest user MP4/audio for promo")
    parser.add_argument("--file", required=True, help="Chemin vers MP4, MOV, MP3…")
    parser.add_argument("--track", help="01-live-liens-verifies | 02-… | 03-…")
    parser.add_argument("--capture", action="store_true", help="Capture écran → assets/captures/")
    args = parser.parse_args()
    ingest(Path(args.file).resolve(), args.track, args.capture)


if __name__ == "__main__":
    main()
