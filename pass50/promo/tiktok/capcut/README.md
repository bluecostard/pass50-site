# CapCut batch — PASS50 TikTok

Import CSV généré automatiquement depuis les scripts JSON.

## Export

```bash
python3 pass50/promo/tiktok/tools/export_capcut_batch.py --day 1
python3 pass50/promo/tiktok/tools/export_capcut_batch.py --all   # J1–J7
```

Fichiers : `capcut/exports/day-NN-capcut.csv`

## Colonnes CSV

| Colonne | Usage CapCut |
|---------|----------------|
| `hook` | Texte accroche (≤ 2 s) |
| `line1`…`line5` | Calques texte écran |
| `voiceover` | Voix off / sous-titres auto |
| `photo1Url`…`photo3Url` | Images profils (glisser-déposer) |
| `videoFile` | Nom d'export MP4 |
| `durationSec` | Durée cible |
| `hashtags` | Description TikTok |

## Template master

Spec complète : [`batch-spec.json`](batch-spec.json)

- Canvas **1080×1920** · fond `#050705` · accent `#b7ff00`
- 1 projet master → dupliquer **12×** par jour
- Musique : bibliothèque TikTok uniquement

## Workflow (≈ 20 min / jour)

1. Exporter le CSV du jour
2. CapCut → Nouveau 9:16 → appliquer le master
3. Pour chaque ligne CSV : coller textes + photos
4. Voix off depuis colonne `voiceover` (ou TTS)
5. Exporter avec le nom `videoFile`
6. TikTok Pro → Planifier (`timeLocal` Abidjan)

## Alternative auto (sans CapCut)

```bash
python3 pass50/promo/tiktok/tools/render_videos.py --day 1 --all-slots
```

MP4 dans `output/day-01/` — prêts à planifier (sans musique ; ajouter dans TikTok).
