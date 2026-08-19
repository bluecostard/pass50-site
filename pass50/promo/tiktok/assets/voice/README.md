# Envois média — campagne app PASS50

Tu peux m’envoyer tes enregistrements **en MP4** (recommandé si tu films avec le téléphone).

## Formats acceptés

| Format | Usage |
|--------|--------|
| **MP4** | ✅ **Recommandé** — face cam, voix + image, capture écran téléphone |
| MOV, WEBM | ✅ Idem |
| MP3, M4A, WAV | ✅ Audio seul |

## Où déposer / envoyer

**Chat Cursor** : glisse le fichier MP4 dans ton message.

**Repo** (si upload possible) :
- Voix / face cam → `pass50/promo/tiktok/assets/voice/`
- Capture écran PASS50 → `pass50/promo/tiktok/assets/captures/`

Nommage :
- `01-live-liens-verifies.mp4`
- `02-anti-faux-comptes.mp4`
- `03-classement-2h.mp4`

## 3 messages à enregistrer

### Message 1 — Live & vrai compte
> Si tu veux tomber sur le vrai compte de ton influenceur préféré, ne pas rater son live, c'est sur PASS50. Tous les liens sont vérifiés et te ramènent directement à la bonne page. Télécharge PASS50.

### Message 2 — Anti faux comptes
> Toi aussi t'en as marre que les gens créent de faux comptes à ton nom ? Sur PASS50, tous les comptes sont vérifiés tous les jours. Certifiés.

### Message 3 — Vrai classement 2 h
> Ça, c'est un vrai classement. Toutes les 2 h, tu as un classement mis à jour de tous les influenceurs tendances ivoiriens. Et ça se passe sur PASS50. Télécharge l'application.

## Conseils MP4 (téléphone)

- **9:16** vertical si tu es face cam (sinon on recadre)
- 10–25 s par prise
- Pas de musique de fond (on l’ajoute au montage)
- Parle clairement, micro proche

## Ce qu’on fait avec ton MP4

1. **Face cam** → montage direct (sous-titres + captures PASS50 + musique)
2. **Capture écran** → intégration dans la vidéo finale
3. **Audio seule dans le MP4** → extraction automatique (`ffmpeg`) si besoin

Script complet : [`../scripts/campaign-core-messages.json`](../scripts/campaign-core-messages.json)
