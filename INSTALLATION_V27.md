# PASS50 — Correctif V27 réel

Ce paquet corrige directement les fichiers déjà chargés par le site. Il ne crée pas un script annexe oublié par `index.html`.

## Corrections

1. Ajout permanent des 6 profils dans `pass50_nouveaux_candidats_90_v19.json`.
2. Migration immédiate de la base 127 vers 133.
3. Liens Facebook `watch/?v=...`, YouTube `watch?v=...`, Reels, TikTok et publications X reconnus comme contenus exacts.
4. La FI et le Top 5 utilisent la meilleure URL validée : `resolvedUrl`, `canonicalUrl`, `submittedUrl`, puis `url`.
5. Les sauvegardes Réseaux + Actualité sont exécutées l'une après l'autre ; une modification faite pendant une sauvegarde déclenche une dernière écriture avec l'état le plus récent.
6. Un LIVE automatique ou manuel ne peut plus rester actif plus de 8 heures sans date de fin valide. Un LIVE sans aucune date expire après 10 minutes.
7. `index.html` passe à `v9-tools.js?v=27.0` pour empêcher Safari et le service worker de conserver l'ancien fichier.

## Installation

Décompressez le ZIP à côté du dépôt PASS50, puis :

```bash
python3 apply_pass50_v27.py /chemin/vers/pass50-site
python3 apply_pass50_v27.py /chemin/vers/pass50-site --check
```

Ensuite, publiez les fichiers modifiés :

- `index.html`
- `v9-tools.js`
- `pass50_nouveaux_candidats_90_v19.json`
- `sw.js` s'il a été modifié

## Sans terminal

1. Ouvrez le fichier actuel `v9-tools.js`.
2. Copiez tout le contenu de `PASS50_V27_AJOUTER_FIN_V9_TOOLS.js` à la fin.
3. Dans `v9-tools.js`, remplacez :
   - `?v=22` du fichier de recensement par `?v=27.0`;
   - `90-v22` par `96-v27`.
4. Ajoutez les 6 objets de `pass50_candidats_v27.json` à la liste JSON existante.
5. Dans `index.html`, remplacez la version du script par :

```html
<script src="./v9-tools.js?v=27.0"></script>
```

## Contrôle après publication

1. Ouvrez un onglet privé.
2. Connectez-vous comme propriétaire.
3. Ouvrez le classement complet : 133 profils doivent être visibles.
4. Dans la console, tapez :

```javascript
PASS50_V27_STATUS
```

Résultat attendu :

```text
total: 133
candidatesPresent: 6
```

5. Le LIVE ancien de Kévine Obin doit avoir disparu.
6. Validez une vidéo Facebook `watch/?v=` : le bouton « Voir l’élément original » doit apparaître dans la FI.
7. Modifiez les réseaux puis l'actualité sans attendre : les deux doivent rester après rechargement.

## Important

Les boutons « Synchroniser les profils » et « Publier les données » ne déploient pas du code. Les fichiers ci-dessus doivent être réellement publiés dans le dépôt/hébergement.
