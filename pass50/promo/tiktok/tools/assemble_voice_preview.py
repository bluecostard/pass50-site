#!/usr/bin/env python3
"""Assemble preview MP4 : voix utilisateur + sous-titres + fond brand (interim)."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "scripts" / "campaign-core-messages.json"
VOICE = ROOT / "assets" / "voice"
OUT = ROOT / "output" / "previews"
W, H = 1080, 1920


def _message(track_id: str, core: dict) -> dict:
    for m in core["messages"]:
        if m["id"] == track_id or track_id.endswith(m["id"]):
            return m
    key = track_id.replace("01-", "").replace("02-", "").replace("03-", "")
    for m in core["messages"]:
        if key in m["id"]:
            return m
    raise KeyError(track_id)


def _audio_path(track_id: str) -> Path:
    for ext in (".mp4", ".m4a", ".mp3", ".mov"):
        p = VOICE / f"{track_id}{ext}"
        if p.exists():
            return p
    raise FileNotFoundError(track_id)


def _duration(audio: Path) -> float:
    out = subprocess.check_output(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(audio),
        ],
        text=True,
    )
    return float(out.strip())


def assemble(track_id: str) -> Path:
    core = json.loads(CORE.read_text(encoding="utf-8"))
    msg = _message(track_id, core)
    audio = _audio_path(track_id)
    duration = _duration(audio)
    lines = msg["onScreen"][: max(1, len(msg["voiceover"]))]
    seg = duration / len(lines)
    font = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

    OUT.mkdir(parents=True, exist_ok=True)
    out_path = OUT / f"preview-{track_id}.mp4"

    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        text_paths: list[str] = []
        for i, line in enumerate(lines):
            tf = tmp_path / f"line{i}.txt"
            tf.write_text(line, encoding="utf-8")
            text_paths.append(str(tf).replace("\\", "/").replace(":", "\\:"))

        filters: list[str] = [f"color=c=0x050705:s={W}x{H}:d={duration}[bg]"]
        prev = "[bg]"
        for i, tpath in enumerate(text_paths):
            start = i * seg
            end = duration if i == len(lines) - 1 else (i + 1) * seg
            out = f"[v{i}]"
            filters.append(
                f"{prev}drawtext=fontfile={font}:textfile={tpath}:reload=1:"
                f"fontcolor=0xEEF2EA:fontsize=52:x=(w-text_w)/2:y=(h-text_h)/2-80:"
                f"enable='between(t,{start:.2f},{end:.2f})'"
                f",drawtext=fontfile={font}:text=PASS50:"
                f"fontcolor=0xB7FF00:fontsize=36:x=80:y=80:"
                f"enable='between(t,{start:.2f},{end:.2f})'"
                f"{out}"
            )
            prev = out
        filters.append(f"{prev}format=yuv420p[vout]")

        cmd = [
            "ffmpeg",
            "-y",
            "-f",
            "lavfi",
            "-i",
            f"color=c=0x050705:s={W}x{H}:d={duration}",
            "-i",
            str(audio),
            "-filter_complex",
            ";".join(filters),
            "-map",
            "[vout]",
            "-map",
            "1:a",
            "-c:v",
            "libx264",
            "-c:a",
            "aac",
            "-shortest",
            "-movflags",
            "+faststart",
            str(out_path),
        ]
        subprocess.run(cmd, check=True, capture_output=True)
    return out_path


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("track", help="01-live-liens-verifies | 02-anti-faux-comptes | …")
    args = parser.parse_args()
    out = assemble(args.track)
    print(f"Wrote {out} ({out.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
