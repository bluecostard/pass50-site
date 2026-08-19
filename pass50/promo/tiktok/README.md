# Promo TikTok PASS50

Kit de production pour **12 vidéos/jour**, toutes les **2 h**, sur **30 jours** (360 posts).

## Contenu du dossier

| Fichier | Rôle |
|---------|------|
| [`brand-kit.json`](brand-kit.json) | Couleurs, CTA, hashtags, specs vidéo |
| [`formats.json`](formats.json) | 12 formats × créneaux horaires (Abidjan) |
| [`calendar-30d.csv`](calendar-30d.csv) | Calendrier complet 360 lignes |
| [`scripts/day-01.json`](scripts/day-01.json) | 12 scripts **remplis** (Top 50 seed : Emma, Lo Père Daloa, etc.) |
| [`data/top50-seed.json`](data/top50-seed.json) | Top 15 + picks promo (gainer, live, spotlight) |
| [`scripts/template.json`](scripts/template.json) | Modèle + placeholders |
| [`export-spec.json`](export-spec.json) | Contrat JSON pour export auto depuis PASS50 |
| [`tools/generate_calendar.py`](tools/generate_calendar.py) | Régénère le CSV |

## Démarrage rapide (jour 1)

1. **Données** — Jour 1 déjà rempli dans `scripts/day-01.json` (source : `data/top50-seed.json`). Pour J2+, copier le modèle ou régénérer depuis le classement live.
3. **Montage** — CapCut : template 9:16, fond `#050705`, accents `#b7ff00`, sous-titres auto.
4. **Planif** — TikTok Pro → Planifier : 12 créneaux du CSV (`timeLocal`, fuseau Abidjan).
5. **Suivi** — Colonne `status` du CSV : `draft` → `rendered` → `scheduled` → `published`.

## Workflow hebdomadaire (recommandé)

| Jour | Action |
|------|--------|
| Dimanche | Remplir scripts J+1…J+7 depuis le classement · batch CapCut |
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
```

Modifie la date de départ dans le script si besoin (`generate(start=date(2026, 8, 20))`).

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
