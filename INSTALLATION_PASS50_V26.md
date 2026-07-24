# PASS50 V26 — correction du compteur 127 → 133

## Cause

La version publiée charge encore `v9-tools.js?v=22`, dont l’import automatique lit uniquement `pass50_nouveaux_candidats_90_v19.json`. Le précédent fichier V24 des six profils n’était pas appelé par `index.html`.

## Correction

`pass50-import-v26.js` :

- ajoute réellement les six fiches à `db.profiles`;
- réapplique l’import après le chargement MySQL;
- sauvegarde l’état avec le compte propriétaire ou administrateur;
- synchronise le registre du Data Hub;
- affiche `V26 · 6/6 nouveaux profils présents`;
- ne donne aucun faux score;
- conserve les six profils non classables;
- ne publie aucun réseau avant validation manuelle.

## Profils

1. African Ryou
2. Samuella Kouassi
3. Nadiani
4. L’Investisseur Africain
5. Laura Ziehi
6. Aya Robert

## Résultat attendu

- avant : 127 profils recensés;
- après : 133 profils recensés;
- les six fiches apparaissent après les profils classables;
- le Top 50 n’est pas modifié.

## Installation automatique

```bash
python3 apply_pass50_v26.py /chemin/vers/pass50-site
python3 apply_pass50_v26.py /chemin/vers/pass50-site --check
```

Publier ensuite les fichiers modifiés.

## Installation manuelle

Téléverser à la racine :

- `pass50-import-v26.js`
- `pass50_nouveaux_candidats_6_v26.json`

Ajouter juste après `v9-tools.js` dans `index.html` :

```html
<script src="./pass50-import-v26.js?v=26.1"></script>
```

## Première ouverture

1. Vider le cache ou utiliser un onglet privé.
2. Se connecter avec le compte propriétaire.
3. Ouvrir Administration → Influenceurs.
4. Vérifier `V26 · 6/6 nouveaux profils présents`.
5. Le compteur doit afficher 133.
6. Dans Data Hub, lancer « Synchroniser les profils » seulement si le registre ne s’actualise pas automatiquement.

La connexion propriétaire est nécessaire pour inscrire durablement l’état 133 dans MySQL.
