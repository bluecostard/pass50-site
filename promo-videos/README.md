# Vidéos promo PASS50

Génère des vidéos animées d’une personne qui utilise PASS50, avec **captures réelles** du site (`pass50.store`) et défilement du classement, des **fiches influenceurs** et des **pronostics**.

Les MP4 durent **15 secondes** et sont **sans audio / sans voix** (piste sonore absente) pour que tu puisses ajouter ta voix à la publication.

## Résultat

| Fichier | Description |
|---------|-------------|
| `output/pass50-personne-defilement.mp4` | 15s · personne + téléphone · classement → fiches → pronos |
| `output/pass50-fullscreen-scroll.mp4` | 15s · plein écran · mêmes scènes |
| `output/pass50-scroll-reel.mp4` | 15s · reel concaténé des captures |

## Usage

```bash
# 1. Capturer (classement live + fiches live + pronos)
node promo-videos/scripts/capture-pass50.mjs

# 2. Rendre les MP4 15s muets (Chrome + ffmpeg)
node promo-videos/scripts/render-promo.mjs
```

Pour les pronostics (page protégée), définir un token API local :

```bash
export PASS50_PROMO_TOKEN="$(cat /tmp/pass50_promo_token.txt)"
export PASS50_PRONO_URL="http://127.0.0.1:8080/pronostics.html"
```

Variables optionnelles :

- `PASS50_URL` — défaut `https://pass50.store/`
- `PASS50_PRONO_URL` — URL pronostics (local recommandé)
- `PASS50_PROMO_TOKEN` — token Bearer pour ouvrir Pronos
- `CHROME_PATH` — défaut `google-chrome`
