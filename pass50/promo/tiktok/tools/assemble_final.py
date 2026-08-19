#!/usr/bin/env python3
"""Montage final v3 — multi-plans rapides, fondus dynamiques, sous-titres voix."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "scripts" / "campaign-core-messages.json"
VOICE = ROOT / "assets" / "voice"
CAPTURES = ROOT / "assets" / "captures"
MANIFEST = CAPTURES / "shots-manifest.json"
OUT = ROOT / "output" / "final"
W, H = 1080, 1920
FPS = 30

# Séquences par défaut (tous les visuels disponibles)
DEFAULT_SEQUENCES = {
    "02-anti-faux-comptes": [
        "02-hero-buzz.png", "04-rank-card-1.png", "05-profile-fiche.png",
        "04-rank-card-2.png", "08-top10-section.png", "09-top10-mini-1.png",
        "09-top10-mini-2.png", "11-modal-top50.png", "11b-top50-row-1.png",
        "11b-top50-row-2.png", "10-tendance.png", "04-rank-card-3.png",
        "01-header-brand.png",
    ],
    "03-classement-2h": [
        "03-filters-period.png", "04-rank-card-1.png", "04-rank-card-2.png",
        "09-top10-mini-1.png", "09-top10-mini-2.png", "09-top10-mini-3.png",
        "08-top10-section.png", "11-modal-top50.png", "11b-top50-row-1.png",
        "13-scroll-900.png", "14-scroll-1400.png", "15-scroll-2000.png",
        "16-scroll-2600.png", "02-hero-buzz.png",
    ],
}

# Durée relative par plan (1.0 = normal, >1 = plan plus long)
SHOT_WEIGHTS = {
    "02-anti-faux-comptes": {
        "02-hero-buzz.png": 1.4,
        "05-profile-fiche.png": 1.5,
        "11-modal-top50.png": 1.6,
        "11b-top50-row-1.png": 1.1,
        "01-header-brand.png": 1.3,
    },
    "03-classement-2h": {
        "03-filters-period.png": 1.2,
        "11-modal-top50.png": 1.4,
        "08-top10-section.png": 1.2,
        "02-hero-buzz.png": 1.3,
    },
}

TRANSITIONS = ["fade", "slideleft", "slideright", "fade", "slideup"]


def _message(track_id: str, core: dict) -> dict:
    for m in core["messages"]:
        if m["id"] in track_id or track_id.endswith(m["id"]):
            return m
    raise KeyError(track_id)


def _audio_path(track_id: str) -> Path:
    for ext in (".mp4", ".m4a", ".mp3"):
        p = VOICE / f"{track_id}{ext}"
        if p.exists():
            return p
    raise FileNotFoundError(track_id)


def _duration(path: Path) -> float:
    out = subprocess.check_output(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", str(path)],
        text=True,
    )
    return float(out.strip())


def _sequences(track_id: str) -> list[str]:
    if MANIFEST.exists():
        data = json.loads(MANIFEST.read_text(encoding="utf-8"))
        seq = data.get("sequences", {}).get(track_id)
        if seq:
            # Filtrer les fichiers manquants
            return [s for s in seq if (CAPTURES / s).exists()]
    seq = DEFAULT_SEQUENCES.get(track_id, ["02-hero-buzz.png"])
    return [s for s in seq if (CAPTURES / s).exists()]


def _resolve_shot(name: str) -> Path:
    p = CAPTURES / name
    if p.exists():
        return p
    for fb in sorted(CAPTURES.glob("04-rank-card-*.png")):
        return fb
    return CAPTURES / "02-hero-buzz.png"


def _shot_durations(track_id: str, shots: list[str], total: float, xfade: float) -> list[float]:
    weights = SHOT_WEIGHTS.get(track_id, {})
    w = [weights.get(s, 1.0) for s in shots]
    n = len(shots)
    # total = sum(d_i) - xfade * (n-1)  →  sum(d_i) = total + xfade*(n-1)
    budget = total + xfade * (n - 1)
    wsum = sum(w)
    return [budget * wi / wsum for wi in w]


def _image_size(img: Path) -> tuple[int, int]:
    out = subprocess.check_output(
        ["ffprobe", "-v", "error", "-select_streams", "v:0",
         "-show_entries", "stream=width,height", "-of", "csv=p=0", str(img)],
        text=True,
    )
    w, h = map(int, out.strip().split(","))
    return w, h


def _clip_from_image(img: Path, seg_d: float, tmp: Path, idx: int, pan: str = "center") -> Path:
    """Plan mobile · zoom lent · blur-fill si crop partiel · grade couleur."""
    out = tmp / f"clip_{idx:02d}.mp4"
    frames = max(3, int(seg_d * FPS))
    iw, ih = _image_size(img)
    needs_fill = abs((iw / ih) - (W / H)) > 0.12

    if pan == "left":
        zexpr = (
            f"zoompan=z='min(1.06+0.08*on/{frames},1.16)':"
            f"x='(iw-iw/zoom)*on/{frames}':y='(ih-ih/zoom)*0.12'"
        )
    elif pan == "right":
        zexpr = (
            f"zoompan=z='min(1.06+0.08*on/{frames},1.16)':"
            f"x='(iw-iw/zoom)*(1-on/{frames})':y='(ih-ih/zoom)*0.10'"
        )
    elif pan == "up":
        zexpr = (
            f"zoompan=z='min(1.05+0.07*on/{frames},1.14)':"
            f"x='iw/2-(iw/zoom/2)':y='(ih-ih/zoom)*0.08*on/{frames}'"
        )
    else:
        zexpr = (
            f"zoompan=z='min(1.05+0.06*on/{frames},1.13)':"
            f"x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'"
        )

    grade = "eq=brightness=0.02:contrast=1.10:saturation=1.15,unsharp=5:5:0.6:5:5:0.0"
    subs = f"drawbox=x=0:y=ih*0.82:w=iw:h=ih*0.18:color=black@0.38:t=fill"

    if needs_fill:
        vf = (
            f"split=2[bg][fg];"
            f"[bg]scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},"
            f"boxblur=28:10,eq=brightness=-0.10:saturation=1.2[bg2];"
            f"[fg]scale={W}:{H}:force_original_aspect_ratio=decrease,"
            f"{grade}[fg2];"
            f"[bg2][fg2]overlay=(W-w)/2:(H-h)/2,"
            f"{zexpr}:d={frames}:s={W}x{H}:fps={FPS},{subs}"
        )
    else:
        vf = (
            f"scale={W * 2}:{H * 2}:force_original_aspect_ratio=increase,"
            f"crop={W}:{H},"
            f"{zexpr}:d={frames}:s={W}x{H}:fps={FPS},"
            f"{grade},{subs}"
        )
    subprocess.run(
        ["ffmpeg", "-y", "-loop", "1", "-i", str(img), "-vf", vf,
         "-t", f"{seg_d:.3f}", "-c:v", "libx264", "-pix_fmt", "yuv420p", str(out)],
        check=True, capture_output=True,
    )
    return out


def _ts(sec: float) -> str:
    h, rem = divmod(int(sec), 3600)
    m, s = divmod(rem, 60)
    ms = int((sec % 1) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def assemble(track_id: str) -> Path:
    core = json.loads(CORE.read_text(encoding="utf-8"))
    msg = _message(track_id, core)
    audio = _audio_path(track_id)
    duration = _duration(audio)
    sub_lines = msg["voiceover"]
    n_sub = len(sub_lines)
    sub_seg = duration / n_sub

    shots = _sequences(track_id)
    if not shots:
        shots = ["02-hero-buzz.png"]

    # Msg court = cuts rapides
    xfade = 0.22 if duration < 15 else 0.28
    durations = _shot_durations(track_id, shots, duration, xfade)

    OUT.mkdir(parents=True, exist_ok=True)
    out_path = OUT / f"final-{track_id}.mp4"

    with tempfile.TemporaryDirectory() as tmpdir:
        tmp = Path(tmpdir)
        clips: list[Path] = []
        pans = ["center", "left", "right", "up", "center"]
        for i, (shot, seg_d) in enumerate(zip(shots, durations)):
            img = _resolve_shot(shot)
            clips.append(_clip_from_image(img, seg_d, tmp, i, pans[i % len(pans)]))

        if len(clips) == 1:
            video_only = clips[0]
        else:
            fc_parts: list[str] = []
            fc_parts.append(f"[0:v]fps={FPS}[v0]")
            offset = durations[0] - xfade
            last = "[v0]"
            for i in range(1, len(clips)):
                fc_parts.append(f"[{i}:v]fps={FPS}[v{i}]")
                tr = TRANSITIONS[i % len(TRANSITIONS)]
                out_label = f"[vx{i}]" if i < len(clips) - 1 else "[vout]"
                fc_parts.append(
                    f"{last}[v{i}]xfade=transition={tr}:duration={xfade}:offset={offset:.3f}{out_label}"
                )
                last = out_label
                offset += durations[i] - xfade
            fc = ";".join(fc_parts)
            inputs: list[str] = ["ffmpeg", "-y"]
            for c in clips:
                inputs.extend(["-i", str(c)])
            inputs.extend([
                "-filter_complex", fc, "-map", "[vout]",
                "-c:v", "libx264", "-pix_fmt", "yuv420p",
                str(tmp / "video_xfade.mp4"),
            ])
            subprocess.run(inputs, check=True, capture_output=True)
            video_only = tmp / "video_xfade.mp4"

        srt = tmp / "subs.srt"
        rows: list[str] = []
        for i, line in enumerate(sub_lines):
            t0 = i * sub_seg
            t1 = duration if i == n_sub - 1 else (i + 1) * sub_seg
            rows += [str(i + 1), f"{_ts(t0)} --> {_ts(t1)}", line, ""]
        srt.write_text("\n".join(rows), encoding="utf-8")

        srt_esc = str(srt).replace("\\", "/").replace(":", "\\:")
        brand = (
            f"subtitles={srt_esc}:force_style="
            "'FontName=DejaVu Sans,Bold=1,FontSize=30,PrimaryColour=&H00EAF2EE,"
            "OutlineColour=&H00000000,Outline=2,Shadow=2,Alignment=2,MarginV=110,"
            "BorderStyle=1',"
            "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:"
            "text='PASS50':fontcolor=0xB7FF00:fontsize=36:x=44:y=44:"
            "shadowcolor=black@0.7:shadowx=2:shadowy=2,"
            "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:"
            "text='pass50.store':fontcolor=0x9DA79B:fontsize=24:x=44:y=86"
        )
        subprocess.run(
            ["ffmpeg", "-y", "-i", str(video_only), "-i", str(audio),
             "-vf", brand, "-map", "0:v", "-map", "1:a",
             "-c:v", "libx264", "-c:a", "aac", "-shortest",
             "-movflags", "+faststart", str(out_path)],
            check=True, capture_output=True,
        )
    return out_path


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("tracks", nargs="*", default=["02-anti-faux-comptes", "03-classement-2h"])
    args = parser.parse_args()
    for t in args.tracks:
        p = assemble(t)
        print(f"Wrote {p} ({p.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
