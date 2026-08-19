# Promo TikTok PASS50

Kit de production pour **12 vidéos/jour**, toutes les **2 h**, sur **30 jours** (360 posts).

## Contenu du dossier

| Fichier | Rôle |
|---------|------|
| [`brand-kit.json`](brand-kit.json) | Couleurs, CTA, hashtags, specs vidéo |
| [`formats.json`](formats.json) | 12 formats × créneaux horaires (Abidjan) |
| [`calendar-30d.csv`](calendar-30d.csv) | Calendrier complet 360 lignes |
| [`scripts/day-01.json` … `day-07.json`](scripts/) | 12 scripts/jour **remplis** (30 profils seed) |
| [`output/day-NN/`](output/) | **MP4 rendus** (auto ou après CapCut) |
| [`capcut/`](capcut/) | Spec template + CSV export CapCut |
| [`render/README.md`](render/README.md) | Rendu auto Pillow + ffmpeg |
| [`data/top50-seed.json`](data/top50-seed.json) | 30 profils + photos + rotations J1–J7 |
| [`scripts/template.json`](scripts/template.json) | Modèle + placeholders |
| [`export-spec.json`](export-spec.json) | Contrat JSON pour export auto depuis PASS50 |
| [`tools/generate_calendar.py`](tools/generate_calendar.py) | Régénère le CSV |
| [`tools/extract_seed_profiles.py`](tools/extract_seed_profiles.py) | Sync profils depuis `index.html` |
| [`tools/generate_scripts.py`](tools/generate_scripts.py) | Régénère scripts J1–J7 |
| [`tools/export_capcut_batch.py`](tools/export_capcut_batch.py) | CSV CapCut par jour |
| [`tools/render_videos.py`](tools/render_videos.py) | **Génère les MP4** depuis les scripts |

## Vidéos prêtes

```bash
# Générer 12 MP4 pour le jour 1 (≈ 1 min)
python3 pass50/promo/tiktok/tools/render_videos.py --day 1 --all-slots

# Semaine 1 complète (84 vidéos)
for d in $(seq 1 7); do python3 pass50/promo/tiktok/tools/render_videos.py --day $d --all-slots; done
```

Sortie : `output/day-01/day-01_slot-01_top3_matin.mp4` etc.  
Calendrier mis à jour (`status=rendered`, `videoFile`).

**CapCut (optionnel)** — affiner musique / voix off : voir [`capcut/README.md`](capcut/README.md).

## Profils intégrés (seed)

Source : `index.html` → `seedProfiles` (30 fiches classables).

**Top 10** : Emma Lohoues, Lo Père Daloa, Paul Yves Ettien, Apoutchou National, Maabio, Teknoush, INadeBelle, Kévine Obin, Edith Brou Bleu, Eunice Zunon.

**Rotations J1–J7** : spotlight, gainer et lives alternent (voir `dayRotations` dans `top50-seed.json`). Exemple J2 — spotlight Maabio, gainer Emma Lohoues, live matin INadeBelle.

## Démarrage rapide (semaine 1)

1. **Scripts** — J1 à J7 prêts dans `scripts/day-NN.json`. Régénérer après MAJ classement :
   ```bash
   python3 pass50/promo/tiktok/tools/extract_seed_profiles.py
   python3 pass50/promo/tiktok/tools/generate_scripts.py
   ```
2. **Montage** — CapCut : template 9:16, fond `#050705`, accents `#b7ff00`, sous-titres auto.
3. **Planif** — TikTok Pro → Planifier : 12 créneaux du CSV (`timeLocal`, fuseau Abidjan). Colonne `notes` = profils du jour.
4. **Suivi** — Colonne `status` : `draft` → `rendered` → `scheduled` → `published`.

## Workflow hebdomadaire (recommandé)

| Jour | Action |
|------|--------|
| Dimanche | Régénérer scripts J+1…J+7 si le classement a bougé · batch CapCut |
| Lun–Sam | 12 posts auto · 15 min/jour vérif commentaires |
| Vendredi | Noter top 3 vidéos (vues, profil cliqué) · ajuster hooks |

## Thèmes par semaine

| Semaine | Angle |
|---------|--------|
| 1 | Découverte — « C'est quoi PASS50 ? » |
| 2 | Lives + profils qui montent |
| 3 | Pronostics + débats |
| 4 | Bilan 30 j |

## Régénérer le calendrier

```bash
python3 pass50/promo/tiktok/tools/generate_calendar.py
python3 pass50/promo/tiktok/tools/generate_scripts.py   # réinjecte notes J1–J7
```

Modifie la date de départ dans `generate_scripts.py` si besoin (`start = date(2026, 8, 20)`).

## Export automatique (phase 2)

Voir [`export-spec.json`](export-spec.json) pour brancher :

- classement public (`app_state`)
- radar live (`p50_live_streams`)
- rendu Creatomate / Remotion / CapCut CSV

Endpoint proposé : `GET /api/tiktok-promo-export.php?date=YYYY-MM-DD`

## Checklist avant publish

- [ ] Hook visible ≤ 2 s
- [ ] Sous-titres activés
- [ ] Musique bibliothèque TikTok
- [ ] 3–5 hashtags (`brand-kit.json`)
- [ ] CTA « lien en bio »
- [ ] Pas d'affirmation diffamatoire sur un profil
