#!/usr/bin/env python3
"""Montage final : voix utilisateur + captures pass50.store + sous-titres."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "scripts" / "campaign-core-messages.json"
VOICE = ROOT / "assets" / "voice"
CAPTURES = ROOT / "assets" / "captures"
OUT = ROOT / "output" / "final"
W, H = 1080, 1920
FPS = 30

TRACK_CAPTURES = {
    "02-anti-faux-comptes": ["pass50-home-top.png", "pass50-ranking-scroll.png", "pass50-home-top.png"],
    "03-classement-2h": ["pass50-ranking-scroll.png", "pass50-ranking-deep.png", "pass50-ranking-scroll.png"],
}


def _message(track_id: str, core: dict) -> dict:
    key = track_id.split("-", 1)[-1] if "-" in track_id else track_id
    for m in core["messages"]:
        if track_id.endswith(m["id"]) or m["id"] in track_id or key in m["id"]:
            return m
    raise KeyError(track_id)


def _audio_path(track_id: str) -> Path:
    for ext in (".mp4", ".m4a", ".mp3"):
        p = VOICE / f"{track_id}{ext}"
        if p.exists():
            return p
    raise FileNotFoundError(track_id)


def _duration(audio: Path) -> float:
    out = subprocess.check_output(
        [
            "ffprobe", "-v", "error", "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1", str(audio),
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
    cap_names = TRACK_CAPTURES.get(track_id, ["pass50-home-top.png"])
    font = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

    OUT.mkdir(parents=True, exist_ok=True)
    out_path = OUT / f"final-{track_id}.mp4"

    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        # Sous-titres SRT
        srt = tmp_path / "subs.srt"
        srt_lines = []
        for i, line in enumerate(lines):
            start = i * seg
            end = duration if i == len(lines) - 1 else (i + 1) * seg
            srt_lines.append(f"{i + 1}")
            srt_lines.append(f"{_ts(start)} --> {_ts(end)}")
            srt_lines.append(line)
            srt_lines.append("")
        srt.write_text("\n".join(srt_lines), encoding="utf-8")

        # Segment vidéo par plan (zoom lent Ken Burns)
        parts: list[Path] = []
        n = len(lines)
        for i in range(n):
            cap = CAPTURES / cap_names[i % len(cap_names)]
            if not cap.exists():
                cap = CAPTURES / "pass50-home-top.png"
            seg_out = tmp_path / f"seg{i}.mp4"
            seg_d = seg if i < n - 1 else duration - i * seg
            frames = max(1, int(seg_d * FPS))
            vf = (
                f"scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},"
                f"zoompan=z='min(1.0+0.04*on/{frames},1.06)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':"
                f"d={frames}:s={W}x{H}:fps={FPS}"
            )
            subprocess.run(
                [
                    "ffmpeg", "-y", "-loop", "1", "-i", str(cap),
                    "-vf", vf, "-t", f"{seg_d:.3f}",
                    "-c:v", "libx264", "-pix_fmt", "yuv420p", str(seg_out),
                ],
                check=True,
                capture_output=True,
            )
            parts.append(seg_out)

        concat = tmp_path / "concat.txt"
        concat.write_text("\n".join(f"file '{p}'" for p in parts) + "\n", encoding="utf-8")
        video_only = tmp_path / "video.mp4"
        subprocess.run(
            ["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat), "-c", "copy", str(video_only)],
            check=True,
            capture_output=True,
        )

        srt_esc = str(srt).replace("\\", "/").replace(":", "\\:")
        subprocess.run(
            [
                "ffmpeg", "-y", "-i", str(video_only), "-i", str(audio),
                "-vf", f"subtitles={srt_esc}:force_style='FontName=DejaVu Sans,FontSize=28,PrimaryColour=&H00EAF2EE,Outline=2,Alignment=2,MarginV=120'",
                "-map", "0:v", "-map", "1:a",
                "-c:v", "libx264", "-c:a", "aac", "-shortest",
                "-movflags", "+faststart", str(out_path),
            ],
            check=True,
            capture_output=True,
        )
    return out_path


def _ts(sec: float) -> str:
    h = int(sec // 3600)
    m = int((sec % 3600) // 60)
    s = int(sec % 60)
    ms = int((sec % 1) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("tracks", nargs="*", default=["02-anti-faux-comptes", "03-classement-2h"])
    args = parser.parse_args()
    for t in args.tracks:
        out = assemble(t)
        print(f"Wrote {out} ({out.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
