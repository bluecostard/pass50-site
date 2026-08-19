#!/usr/bin/env python3
"""Génère scripts/day-NN.json depuis top50-seed.json + formats.json."""

from __future__ import annotations

import json
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEED_PATH = ROOT / "data" / "top50-seed.json"
FORMATS_PATH = ROOT / "formats.json"
SCRIPTS_DIR = ROOT / "scripts"


def _by_name(profiles: list[dict]) -> dict[str, dict]:
    return {p["name"]: p for p in profiles}


def _fmt_top3_voice(profiles: list[dict]) -> list[str]:
    lines = ["Top 3 PASS50 — période 24 heures."]
    for i, p in enumerate(profiles[:3], 1):
        lines.append(f"{i} — {p['name']} · {p['score24H']} points")
    lines.append("Classement complet : lien en bio.")
    return lines


def _fmt_top3_screen(profiles: list[dict], title: str = "TOP 3 · 24H") -> list[str]:
    rows = [title]
    for i, p in enumerate(profiles[:3], 1):
        rows.append(f"#{i} {p['name']}")
    rows.append("pass50.store")
    return rows


def _fmt_top10_voice(profiles: list[dict]) -> list[str]:
    names = [p["name"] for p in profiles[:10]]
    return [
        "Voici le Top 10 PASS50 sur 24 heures.",
        ", ".join(names[:5]) + ".",
        ", ".join(names[5:]) + ".",
        "Le détail sur pass50.store.",
    ]


def _fmt_top10_screen(profiles: list[dict]) -> list[str]:
    rows = ["TOP 10 · 24H"]
    for i, p in enumerate(profiles[:10], 1):
        if i <= 5:
            rows.append(f"{i} {p['name']}")
        elif i == 6:
            chunk = " · ".join(f"{j} {profiles[j - 1]['name']}" for j in range(6, 9))
            rows.append(chunk)
        elif i == 9:
            chunk = " · ".join(f"{j} {profiles[j - 1]['name']}" for j in range(9, 11))
            rows.append(chunk)
    rows.append("pass50.store")
    return rows


def _fmt_top5_voice(profiles: list[dict]) -> list[str]:
    lines = ["Top 5 PASS50 sur 7 jours."]
    for i, p in enumerate(profiles[:5], 1):
        lines.append(f"{i} {p['name']}")
    lines.append("Le marathon compte autant que le sprint.")
    return lines


def _fmt_top5_screen(profiles: list[dict]) -> list[str]:
    rows = ["TOP 5 · 7J"]
    for i, p in enumerate(profiles[:5], 1):
        rows.append(f"{i} {p['name']}")
    rows.append("pass50.store")
    return rows


def build_slot(
    fmt: dict,
    day: int,
    profiles: list[dict],
    picks: dict,
    hook: str,
) -> dict:
    top3 = profiles[:3]
    gainer = _by_name(profiles)[picks["gainer"]]
    spotlight = _by_name(profiles)[picks["spotlight"]]
    live_am = _by_name(profiles)[picks["liveMorning"]]
    live_pm = _by_name(profiles)[picks["liveEvening"]]
    fid = fmt["id"]

    slot: dict = {
        "slot": fmt["slot"],
        "timeLocal": fmt["timeLocal"],
        "formatId": fid,
        "hook": hook,
        "durationSec": fmt["durationSec"],
        "voiceover": [],
        "onScreen": [],
        "broll": [],
        "hashtags": "#pass50 #pass50store #classement #influenceur #tiktokci",
    }

    if fid == "top3_matin":
        slot["voiceover"] = _fmt_top3_voice(top3)
        slot["onScreen"] = _fmt_top3_screen(top3)
        slot["broll"] = [f"Photo {p['name']}" for p in top3] + ["Logo PASS50"]
    elif fid == "live_radar_matin":
        slot["voiceover"] = [
            f"{live_am['name']} est en live sur TikTok.",
            "Le radar PASS50 vient de le détecter.",
            "Va voir le classement pendant le live.",
        ]
        slot["onScreen"] = ["🔴 LIVE RADAR", live_am["name"], live_am["handle"], "TikTok", "pass50.store"]
        slot["broll"] = [f"Photo {live_am['name']}", "Badge LIVE", "Animation radar"]
        slot["hashtags"] = "#pass50 #live #radar #influenceur #abidjan"
    elif fid == "fi_spotlight":
        delta_txt = f"+{spotlight['delta']}" if spotlight["delta"] >= 0 else str(spotlight["delta"])
        slot["voiceover"] = [
            f"FI du jour sur PASS50 : {spotlight['name']}.",
            f"Score 24 h : {spotlight['score24H']} points.",
            f"{delta_txt} places gagnées récemment.",
            "Tu le suis déjà ?",
        ]
        slot["onScreen"] = [
            "FI DU JOUR",
            spotlight["name"],
            spotlight["handle"],
            f"Score {spotlight['score24H']}",
            "pass50.store",
        ]
        slot["broll"] = [f"Photo {spotlight['name']}", "Courbe score", "Logo PASS50"]
    elif fid == "classement_24h":
        slot["voiceover"] = _fmt_top10_voice(profiles)
        slot["onScreen"] = _fmt_top10_screen(profiles)
        slot["broll"] = ["Liste animée Top 10", "Transitions 0.8 s/nom"]
    elif fid == "sondage":
        slot["voiceover"] = [
            "Débat PASS50.",
            "Qui finira numéro 1 ce soir ?",
            "Commente 1, 2 ou 3.",
            f"{top3[0]['name']}, {top3[1]['name']} ou {top3[2]['name']} — on compare demain matin.",
        ]
        slot["onScreen"] = [
            "TU MISERais SUR QUI ?",
            f"1 {top3[0]['name']}",
            f"2 {top3[1]['name']}",
            f"3 {top3[2]['name']}",
            "Commente 👇",
        ]
        slot["broll"] = [f"Photo {p['name']}" for p in top3] + ["Sticker sondage TikTok optionnel"]
    elif fid == "buzz_montee":
        delta = gainer["delta"]
        sign = f"+{delta}" if delta >= 0 else str(delta)
        slot["voiceover"] = [
            f"Buzz PASS50 : {gainer['name']} monte fort.",
            f"{sign} places.",
            f"Score actuel : {gainer['score24H']} points.",
            "Classement sur pass50.store.",
        ]
        slot["onScreen"] = ["📈 MONTÉE", gainer["name"], gainer["handle"], f"{sign} places", "pass50.store"]
        slot["broll"] = [f"Photo {gainer['name']}", "Flèche verte animée"]
    elif fid == "live_radar_soir":
        slot["voiceover"] = [
            f"Ce soir sur le radar PASS50 : {live_pm['name']}.",
            "En live sur TikTok.",
            "Lien en bio pour le classement.",
        ]
        slot["onScreen"] = ["🔴 SOIRÉE LIVE", live_pm["name"], live_pm["handle"], "pass50.store"]
        slot["broll"] = [f"Photo {live_pm['name']}", "Badge LIVE"]
    elif fid == "top3_soir":
        slot["voiceover"] = [
            "Top 3 du soir PASS50.",
            *[f"{i} {p['name']}" for i, p in enumerate(top3, 1)],
            "Demain matin : nouvelle mise à jour.",
        ]
        slot["onScreen"] = ["TOP 3 SOIR"] + [f"#{i} {p['name']}" for i, p in enumerate(top3, 1)]
        slot["broll"] = ["Photos podium", "Animation 1-2-3"]
    elif fid == "recap_jour":
        slot["voiceover"] = [
            "Récap du jour sur PASS50.",
            f"Leader : {top3[0]['name']}.",
            f"Plus grosse montée : {gainer['name']}.",
            f"{picks['liveCount']} lives détectés sur le radar.",
            "Rendez-vous demain 7 h.",
        ]
        slot["onScreen"] = [
            "RÉCAP JOUR",
            f"👑 {top3[0]['name']}",
            f"📈 {gainer['name']}",
            f"🔴 {picks['liveCount']} lives",
            "pass50.store",
        ]
        slot["broll"] = ["Montage 3 cartes", "Logo fin"]
    elif fid == "teaser_lendemain":
        slot["voiceover"] = [
            "Le classement ne dort jamais.",
            "Demain 7 h : nouveau Top 3 PASS50.",
            f"{top3[0]['name']}, {top3[1]['name']} ou {top3[2]['name']} — qui mène ?",
            "Active la cloche.",
        ]
        slot["onScreen"] = ["DEMAIN 7H", "NOUVEAU TOP 3", "PASS50", "pass50.store"]
        slot["broll"] = ["Logo animé", "Horloge"]
    elif fid == "coulisses":
        slot["voiceover"] = [
            "C'est quoi PASS50 ?",
            "On mesure l'activité publique : posts, vues, lives, signaux officiels.",
            f"Exemple aujourd'hui : {top3[0]['name']} en tête, {gainer['name']} en montée.",
            "Classement sur pass50.store.",
        ]
        slot["onScreen"] = ["COULISSES PASS50", "Signaux publics", "Scores · Périodes · Radar live", "pass50.store"]
        slot["broll"] = ["Capture site", "Icônes plateformes"]
    elif fid == "classement_7j":
        slot["voiceover"] = _fmt_top5_voice(profiles)
        slot["onScreen"] = _fmt_top5_screen(profiles)
        slot["broll"] = ["Liste Top 5", "Badge 7J"]

    return slot


def hook_for(fmt: dict, day: int) -> str:
    variants = fmt.get("hookVariants") or [fmt["label"]]
    return variants[(day - 1) % len(variants)]


def week_theme(day: int, formats: dict) -> tuple[int, str, str]:
    for block in formats["weeklyThemes"]:
        start, end = block["days"]
        if start <= day <= end:
            return block["week"], block["theme"], block["overlay"]
    last = formats["weeklyThemes"][-1]
    return last["week"], last["theme"], last["overlay"]


def generate_day(day: int, start: date, seed: dict, formats: dict) -> dict:
    profiles = seed["profiles"]
    rotations = {r["day"]: r for r in seed.get("dayRotations", [])}
    picks = rotations.get(day, rotations.get(1, {}))
    day_date = start + timedelta(days=day - 1)
    week, theme, overlay = week_theme(day, formats)

    slots = [
        build_slot(fmt, day, profiles, picks, hook_for(fmt, day))
        for fmt in formats["formats"]
    ]

    return {
        "version": "TIKTOK-SCRIPTS-V1.0",
        "day": day,
        "date": day_date.isoformat(),
        "week": week,
        "theme": theme,
        "themeOverlay": overlay,
        "dataSource": "pass50/promo/tiktok/data/top50-seed.json",
        "profilesUsed": {
            "top3": [p["name"] for p in profiles[:3]],
            "top10": [p["name"] for p in profiles[:10]],
            "top5_7j": [p["name"] for p in profiles[:5]],
            "gainer": picks.get("gainer"),
            "spotlight": picks.get("spotlight"),
            "liveMorning": picks.get("liveMorning"),
            "liveEvening": picks.get("liveEvening"),
        },
        "slots": slots,
    }


def main() -> None:
    seed = json.loads(SEED_PATH.read_text(encoding="utf-8"))
    formats = json.loads(FORMATS_PATH.read_text(encoding="utf-8"))
    start = date(2026, 8, 20)
    SCRIPTS_DIR.mkdir(parents=True, exist_ok=True)
    generated: list[tuple[int, dict]] = []

    for day in range(1, 8):
        payload = generate_day(day, start, seed, formats)
        out = SCRIPTS_DIR / f"day-{day:02d}.json"
        out.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        generated.append((day, payload))
        print(f"Wrote {out.name} ({len(payload['slots'])} slots)")

    _enrich_calendar_notes(generated)


def _enrich_calendar_notes(generated: list[tuple[int, dict]]) -> None:
    """Ajoute les noms profils dans calendar-30d.csv (colonne notes) pour J1–J7."""
    import csv

    cal_path = ROOT / "calendar-30d.csv"
    if not cal_path.exists():
        return

    notes_by_key: dict[tuple[str, str], str] = {}
    for day, payload in generated:
        pu = payload["profilesUsed"]
        note = (
            f"top3={','.join(pu['top3'][:3])};"
            f"spotlight={pu['spotlight']};gainer={pu['gainer']};"
            f"liveAM={pu['liveMorning']};livePM={pu['liveEvening']}"
        )
        for slot in payload["slots"]:
            notes_by_key[(str(day), str(slot["slot"]))] = note

    rows = list(csv.DictReader(cal_path.open(encoding="utf-8")))
    fieldnames = list(rows[0].keys()) if rows else []
    for row in rows:
        key = (row["day"], row["slot"])
        if key in notes_by_key:
            row["notes"] = notes_by_key[key]

    with cal_path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
    print(f"Enriched calendar notes for days 1–{len(generated)} → {cal_path}")


if __name__ == "__main__":
    main()
