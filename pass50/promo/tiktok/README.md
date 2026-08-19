# Promo TikTok PASS50

Campagne **app & confiance** — pas des slides classement.

## Messages cœur (fournis par l'équipe)

| ID | Angle | Texte |
|----|-------|-------|
| `live-liens-verifies` | Live + vrai compte | Vrai compte, ne pas rater le live → PASS50. Liens vérifiés. **Télécharge PASS50.** |
| `anti-faux-comptes` | Anti-usurpation | Marre des faux comptes ? Comptes **vérifiés tous les jours**, **certifiés**. |
| `classement-2h` | Classement CI | **Vrai classement** · mis à jour **toutes les 2 h** · influenceurs tendances ivoiriens → **Télécharge l'application** |

## ⚠️ Envoyer tes MP4 — le chat ne les accepte pas

**Les pièces jointes MP4 ne passent pas dans Cursor.** Utilise :

1. **Lien WeTransfer / Drive / Dropbox** → colle l’URL dans le chat  
2. **Upload GitHub** → [dossier assets/voice](https://github.com/bluecostard/pass50-site/tree/cursor/tiktok-promo-kit-0a21/pass50/promo/tiktok/assets/voice)  
3. **Git push** en local

Guide complet : [`assets/ENVOYER-MP4.md`](assets/ENVOYER-MP4.md)

## Fichiers principaux

| Fichier | Rôle |
|---------|------|
| [`scripts/app-promo/day-01.json` … `day-07.json`](scripts/app-promo/) | 12 créneaux/jour · voix off + direction visuelle UGC |
| [`capcut/batch-spec-app-promo.json`](capcut/batch-spec-app-promo.json) | Template CapCut (capture app, face cam) |
| [`tools/generate_app_promo_scripts.py`](tools/generate_app_promo_scripts.py) | Régénère J1–J7 depuis les messages cœur |

## Production (CapCut / UGC)

1. Lire `scripts/app-promo/day-NN.json` → `voiceover` + `visualDirection.scenes`
2. Tourner ou capturer : **radar live**, **fiche FI certifiée**, **tap lien vérifié**
3. Musique trend TikTok + sous-titres auto
4. Exporter 1080×1920 · planifier (créneaux `formats.json`)

```bash
python3 pass50/promo/tiktok/tools/generate_app_promo_scripts.py
python3 pass50/promo/tiktok/tools/export_capcut_batch.py --app-promo --day 1
```

## ⚠️ Rendu slide ranking (déprécié)

Les MP4 dans `output/` (fond noir + liste Top 10) **ne correspondent pas** au format souhaité. Ne pas utiliser pour la campagne app. Conservés pour référence technique uniquement.

## Ajouter un message

Éditer `campaign-core-messages.json` → relancer `generate_app_promo_scripts.py`.

## Calendrier 30 j

[`calendar-30d.csv`](calendar-30d.csv) — 12 posts/2 h · fuseau Abidjan. Colonnes `timeLocal` + `hashtags` toujours valides ; ignorer les anciennes notes ranking.
