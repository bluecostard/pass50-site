#!/usr/bin/env python3
"""Rendu MP4 9:16 depuis scripts/day-NN.json (Pillow + ffmpeg)."""

from __future__ import annotations

import argparse
import csv
import json
import re
import subprocess
import tempfile
import urllib.request
from io import BytesIO
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
SEED = ROOT / "data" / "top50-seed.json"
BRAND = ROOT / "brand-kit.json"
OUT_ROOT = ROOT / "output"
CALENDAR = ROOT / "calendar-30d.csv"

W, H = 1080, 1920
FPS = 30
FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_REG = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def _hex(color: str) -> tuple[int, int, int]:
    color = color.lstrip("#")
    return tuple(int(color[i : i + 2], 16) for i in (0, 2, 4))


def _load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    path = FONT_BOLD if bold else FONT_REG
    return ImageFont.truetype(path, size)


def _wrap_text(draw: ImageDraw.ImageDraw, text: str, font: ImageFont.FreeTypeFont, max_w: int) -> list[str]:
    words = text.split()
    if not words:
        return [""]
    lines: list[str] = []
    current = words[0]
    for word in words[1:]:
        trial = f"{current} {word}"
        if draw.textlength(trial, font=font) <= max_w:
            current = trial
        else:
            lines.append(current)
            current = word
    lines.append(current)
    return lines


def _download_image(url: str, cache: dict[str, Image.Image]) -> Image.Image | None:
    if not url:
        return None
    if url in cache:
        return cache[url]
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "PASS50-Promo-Render/1.0"})
        with urllib.request.urlopen(req, timeout=15) as resp:
            img = Image.open(BytesIO(resp.read())).convert("RGBA")
        cache[url] = img
        return img
    except Exception:
        return None


def _draw_circle_photo(
    base: Image.Image,
    draw: ImageDraw.ImageDraw,
    center: tuple[int, int],
    radius: int,
    photo: Image.Image | None,
    initials: str,
    accent: tuple[int, int, int],
) -> None:
    x, y = center
    bbox = (x - radius, y - radius, x + radius, y + radius)
    mask = Image.new("L", (radius * 2, radius * 2), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, radius * 2 - 1, radius * 2 - 1), fill=255)
    if photo:
        photo = photo.copy()
        photo = photo.resize((radius * 2, radius * 2), Image.Resampling.LANCZOS)
        layer = Image.new("RGBA", base.size, (0, 0, 0, 0))
        layer.paste(photo, (x - radius, y - radius), mask)
        base.alpha_composite(layer)
    else:
        draw.ellipse(bbox, fill=(20, 30, 20), outline=accent, width=4)
        font = _load_font(max(28, radius // 2), bold=True)
        tw = draw.textlength(initials, font=font)
        draw.text((x - tw / 2, y - font.size // 2), initials, fill=accent, font=font)
    draw.ellipse(bbox, outline=accent, width=4)


def _profile_photos(slot: dict, by_name: dict[str, dict], cache: dict[str, Image.Image]) -> list[tuple[Image.Image | None, str]]:
    text = " ".join(slot.get("onScreen", []) + slot.get("voiceover", []))
    found: list[tuple[Image.Image | None, str]] = []
    for p in by_name.values():
        if p["name"] in text:
            img = _download_image(p.get("photoUrl") or p.get("photoCandidateUrl") or "", cache)
            found.append((img, p.get("initials") or p["name"][:2].upper()))
        if len(found) >= 3:
            break
    while len(found) < 3:
        found.append((None, ""))
    return found


def render_frame(
    hook: str,
    lines: list[str],
    visible_count: int,
    photos: list[tuple[Image.Image | None, str]],
    brand: dict,
) -> Image.Image:
    colors = brand["colors"]
    bg = _hex(colors["background"])
    lime = _hex(colors["lime"])
    text_c = _hex(colors["text"])
    muted = _hex(colors["muted"])

    img = Image.new("RGBA", (W, H), bg + (255,))
    draw = ImageDraw.Draw(img)

    # Wordmark top
    wm_font = _load_font(36, bold=True)
    draw.text((80, 60), "PASS", fill=text_c, font=wm_font)
    draw.text((80 + draw.textlength("PASS", font=wm_font), 60), "50", fill=lime, font=wm_font)

    # Hook
    hook_font = _load_font(52, bold=True)
    for i, line in enumerate(_wrap_text(draw, hook, hook_font, W - 160)):
        draw.text((80, 180 + i * 62), line, fill=lime, font=hook_font)

    # Visible onScreen lines
    y = 400
    for idx, line in enumerate(lines[:6]):
        if idx >= visible_count:
            break
        is_title = idx == 0
        font = _load_font(46 if is_title else 38, bold=is_title)
        color = text_c if idx < 4 else muted
        for sub in _wrap_text(draw, line, font, W - 160):
            draw.text((80, y), sub, fill=color, font=font)
            y += int(font.size * 1.25)

    # Photos
    if photos[0][1]:
        _draw_circle_photo(img, draw, (W // 2, 1180), 140, photos[0][0], photos[0][1], lime)
    if photos[1][1]:
        _draw_circle_photo(img, draw, (280, 1420), 90, photos[1][0], photos[1][1], lime)
    if photos[2][1]:
        _draw_circle_photo(img, draw, (800, 1420), 90, photos[2][0], photos[2][1], lime)

    # CTA footer
    cta_font = _load_font(32, bold=True)
    draw.text((80, H - 120), "pass50.store", fill=lime, font=cta_font)
    sub_font = _load_font(24)
    draw.text((80, H - 75), brand.get("ctaPrimary", "Lien en bio"), fill=muted, font=sub_font)

    return img


def render_slot_video(
    slot: dict,
    brand: dict,
    by_name: dict[str, dict],
    out_path: Path,
    fps: int = FPS,
) -> None:
    duration = int(slot.get("durationSec", 18))
    lines = slot.get("onScreen") or [slot.get("hook", "PASS50")]
    hook = slot.get("hook", "PASS50")
    n_lines = max(1, len(lines))
    cache: dict[str, Image.Image] = {}
    photos = _profile_photos(slot, by_name, cache)
    seg_duration = duration / n_lines

    out_path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        concat_lines: list[str] = []
        for visible in range(1, n_lines + 1):
            frame = render_frame(hook, lines, visible, photos, brand)
            png = tmp_path / f"step_{visible:02d}.png"
            frame.convert("RGB").save(png)
            concat_lines.append(f"file '{png}'")
            concat_lines.append(f"duration {seg_duration:.3f}")
        concat_lines.append(f"file '{tmp_path / f'step_{n_lines:02d}.png'}'")
        concat_file = tmp_path / "concat.txt"
        concat_file.write_text("\n".join(concat_lines) + "\n", encoding="utf-8")

        cmd = [
            "ffmpeg",
            "-y",
            "-f",
            "concat",
            "-safe",
            "0",
            "-i",
            str(concat_file),
            "-vf",
            f"fps={fps},format=yuv420p",
            "-c:v",
            "libx264",
            "-movflags",
            "+faststart",
            str(out_path),
        ]
        subprocess.run(cmd, check=True, capture_output=True)


def _video_filename(day: int, slot: dict) -> str:
    slug = re.sub(r"[^a-zA-Z0-9_-]+", "-", slot["formatId"].lower()).strip("-")
    return f"day-{day:02d}_slot-{slot['slot']:02d}_{slug}.mp4"


def _update_calendar(day: int, mapping: dict[int, str]) -> None:
    if not CALENDAR.exists():
        return
    rows = list(csv.DictReader(CALENDAR.open(encoding="utf-8")))
    if not rows:
        return
    fields = list(rows[0].keys())
    for row in rows:
        if row["day"] == str(day) and int(row["slot"]) in mapping:
            row["videoFile"] = mapping[int(row["slot"])]
            row["status"] = "rendered"
    with CALENDAR.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def sync_calendar_from_output(days: range | None = None) -> None:
    """Met à jour calendar-30d.csv depuis output/day-NN/ sans re-rendre."""
    if not CALENDAR.exists():
        return
    day_range = days or range(1, 8)
    rows = list(csv.DictReader(CALENDAR.open(encoding="utf-8")))
    if not rows:
        return
    fields = list(rows[0].keys())
    for row in rows:
        day = int(row["day"])
        if day not in day_range:
            continue
        slot = int(row["slot"])
        glob = OUT_ROOT / f"day-{day:02d}" / f"day-{day:02d}_slot-{slot:02d}_*.mp4"
        matches = list(glob.parent.glob(glob.name))
        if matches:
            row["videoFile"] = str(matches[0].relative_to(ROOT))
            row["status"] = "rendered"
    with CALENDAR.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def render_day(day: int, slots: list[int] | None = None) -> list[Path]:
    script_path = SCRIPTS / f"day-{day:02d}.json"
    day_data = json.loads(script_path.read_text(encoding="utf-8"))
    brand = json.loads(BRAND.read_text(encoding="utf-8"))
    seed = json.loads(SEED.read_text(encoding="utf-8"))
    by_name = {p["name"]: p for p in seed["profiles"]}

    out_dir = OUT_ROOT / f"day-{day:02d}"
    outputs: list[Path] = []
    cal_map: dict[int, str] = {}

    for slot in day_data["slots"]:
        if slots and slot["slot"] not in slots:
            continue
        fname = _video_filename(day, slot)
        out_path = out_dir / fname
        print(f"Rendering {out_path.name} …")
        render_slot_video(slot, brand, by_name, out_path)
        outputs.append(out_path)
        cal_map[slot["slot"]] = str(out_path.relative_to(ROOT))
        print(f"  → {out_path} ({out_path.stat().st_size // 1024} KB)")

    _update_calendar(day, cal_map)
    return outputs


def main() -> None:
    parser = argparse.ArgumentParser(description="Render TikTok promo MP4s")
    parser.add_argument("--day", type=int, default=1)
    parser.add_argument("--slot", type=int, action="append", help="Slot(s) only, e.g. --slot 1")
    parser.add_argument("--all-slots", action="store_true", help="All 12 slots of the day")
    parser.add_argument("--sync-calendar", action="store_true", help="Sync CSV from existing MP4")
    args = parser.parse_args()

    if args.sync_calendar:
        sync_calendar_from_output(range(1, args.day + 1) if args.day else range(1, 8))
        return

    slots = args.slot
    if args.all_slots:
        slots = None
    elif slots is None:
        slots = [1]

    render_day(args.day, slots)


if __name__ == "__main__":
    main()
