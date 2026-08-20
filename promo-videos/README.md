# Vidéos promo PASS50

Génère des vidéos animées d’une personne qui utilise PASS50, avec **captures réelles** du site (`pass50.store`) et défilement du classement.

Les MP4 sont **sans audio / sans voix** (piste sonore absente) pour que tu puisses ajouter ta voix à la publication.

## Résultat

| Fichier | Description |
|---------|-------------|
| `output/pass50-personne-defilement.mp4` | Personne + téléphone, scroll réel du classement (9:16) |
| `output/pass50-fullscreen-scroll.mp4` | Plein écran, même défilement réel |
| `output/pass50-scroll-reel.mp4` | Reel rapide à partir des frames capturées |

## Usage

```bash
# 1. Capturer le site live (mobile 390×844)
node promo-videos/scripts/capture-pass50.mjs

# 2. Rendre les MP4 (Chrome headless + ffmpeg)
node promo-videos/scripts/render-promo.mjs
```

Variables optionnelles :

- `PASS50_URL` — défaut `https://pass50.store/`
- `CHROME_PATH` — défaut `google-chrome`

## Prérequis

- Google Chrome / Chromium
- ffmpeg
- paquet `ws` dans `promo-videos/.deps` (téléchargé automatiquement au premier setup)

```bash
mkdir -p promo-videos/.deps/node_modules
curl -fsSL https://registry.npmjs.org/ws/-/ws-8.18.3.tgz | tar -xz -C promo-videos/.deps/node_modules
mv promo-videos/.deps/node_modules/package promo-videos/.deps/node_modules/ws
```

## Aperçu template

Ouvre `templates/person-using-pass50.html` dans Chrome (via un serveur local pour charger les frames) :

```bash
php -S 127.0.0.1:8765 -t promo-videos
# puis http://127.0.0.1:8765/templates/person-using-pass50.html?mode=person
```
