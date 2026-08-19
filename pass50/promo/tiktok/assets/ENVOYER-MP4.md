# Comment m’envoyer tes MP4 (le chat Cursor ne les accepte pas)

Le **chat ne prend pas les MP4 en pièce jointe**. Utilise une de ces méthodes :

---

## Méthode 1 — Lien de téléchargement (la plus simple)

1. Upload ton MP4 sur **WeTransfer**, **SwissTransfer**, **Dropbox**, ou **Google Drive**
2. Génère un **lien de téléchargement direct**
3. Colle le lien **dans le chat**, avec le numéro du message :

```
Message 1 : https://…/01-live-liens-verifies.mp4
Message 2 : https://…/02-anti-faux-comptes.mp4
Message 3 : https://…/03-classement-2h.mp4
```

Je télécharge et intègre automatiquement.

---

## Méthode 2 — GitHub (upload web)

1. Ouvre :  
   https://github.com/bluecostard/pass50-site/tree/cursor/tiktok-promo-kit-0a21/pass50/promo/tiktok/assets/voice
2. **Add file → Upload files**
3. Glisse tes MP4 (nomme-les `01-live-liens-verifies.mp4`, etc.)
4. **Commit** sur la branche `cursor/tiktok-promo-kit-0a21`
5. Écris-moi : « MP4 uploadés sur GitHub »

---

## Méthode 3 — Git en local (si tu as le repo)

```bash
cp ton-fichier.mp4 pass50/promo/tiktok/assets/voice/01-live-liens-verifies.mp4
git add pass50/promo/tiktok/assets/voice/
git commit -m "voice: message 1"
git push origin cursor/tiktok-promo-kit-0a21
```

Puis dis-moi « c’est poussé ».

---

## Noms de fichiers

| Fichier | Contenu |
|---------|---------|
| `01-live-liens-verifies.mp4` | Message live + vrai compte |
| `02-anti-faux-comptes.mp4` | Message faux comptes |
| `03-classement-2h.mp4` | Message classement 2 h |

Captures écran PASS50 → `assets/captures/` (ex. `capture-classement.mp4`)

---

## Si rien ne marche

Envoie un **lien WeTransfer** même sans compte — ça suffit.  
Ou décris le blocage exact (message d’erreur) et on trouve une autre route.
