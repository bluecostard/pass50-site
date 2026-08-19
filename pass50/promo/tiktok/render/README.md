# Rendu vidéo auto — PASS50 TikTok

Génère des **MP4 1080×1920** depuis `scripts/day-NN.json` sans CapCut.

## Prérequis

- `ffmpeg` (installé sur l'environnement cloud)
- `Pillow` (`pip install pillow`)

## Commandes

```bash
# J1 · slot 1 seul (test rapide)
python3 pass50/promo/tiktok/tools/render_videos.py --day 1 --slot 1

# J1 · 12 vidéos complètes
python3 pass50/promo/tiktok/tools/render_videos.py --day 1 --all-slots

# J1–J7
for d in $(seq 1 7); do
  python3 pass50/promo/tiktok/tools/render_videos.py --day $d --all-slots
done
```

## Sortie

```
pass50/promo/tiktok/output/day-01/
  day-01_slot-01_top3_matin.mp4
  day-01_slot-02_live_radar_matin.mp4
  …
```

Le calendrier `calendar-30d.csv` est mis à jour : `status=rendered`, `videoFile` renseigné.

## Contenu rendu

- Fond brand `#050705`, accents `#b7ff00`
- Hook + textes `onScreen` (apparition progressive)
- Photos profils quand URL disponible dans `top50-seed.json` (sinon initiales)
- **Pas de voix off ni musique** — ajouter dans TikTok avant publish

## CapCut vs auto

| | Auto (`render_videos.py`) | CapCut batch |
|--|---------------------------|--------------|
| Vitesse | 12 MP4 en ~3 min | ~2 h montage manuel |
| Qualité | Propre, sobre | Personnalisable |
| Musique | Non | Oui |
| Voix off | Non | Oui / TTS |

Recommandation : **auto-render** pour volume, **CapCut** pour affiner le Top 3 du matin.

## Remotion (phase 3)

Pour des templates motion avancés, brancher `export-spec.json` sur Remotion/Creatomate — le contrat JSON est déjà défini.
