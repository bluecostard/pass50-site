# PASS50 V24 — installation

## Contenu

- `pass50-candidats-v24.js` : intégration dédupliquée de 6 candidats approuvés.
- `pass50-candidats-v24.json` : données et sources de contrôle.
- `.htaccess.pass50-v24` : redirection HTTPS et en-têtes de sécurité.
- `apply_pass50_v24.py` : installation automatique dans le dépôt existant.
- `SECURITE_PASS50_V24.md` : diagnostic et étapes de sécurisation.

## Installation automatique

1. Décompresser ce dossier.
2. Copier le dossier ou seulement ses fichiers à côté du dépôt `pass50-site`.
3. Dans un terminal :

```bash
python3 apply_pass50_v24.py /chemin/vers/pass50-site
```

4. Contrôler :

```bash
python3 apply_pass50_v24.py /chemin/vers/pass50-site --check
```

5. Commit/push GitHub ou téléverser les fichiers sur IONOS.

## Installation manuelle

1. Copier `pass50-candidats-v24.js` à la racine du site.
2. Dans `index.html`, juste après la balise qui charge `v9-tools.js`, ajouter :

```html
<script src="./pass50-candidats-v24.js?v=24"></script>
```

3. Activer le certificat SSL dans **IONOS → Domaines & SSL**.
4. Fusionner le contenu de `.htaccess.pass50-v24` avec le `.htaccess` existant.
5. Recharger le site en étant connecté comme propriétaire.

## Résultat attendu

Les fiches suivantes apparaissent dans le recensement, après les profils classés :

- African Ryou
- Samuella Kouassi
- Nadiani
- L’Investisseur Africain
- Laura Ziehi
- Aya Robert

Aucun de ces profils ne reçoit de score fictif. Ils restent `eligible:false` et `classable:false` jusqu’au contrôle des métriques récentes. Laura Ziehi et Aya Robert n’exposent aucun lien social tant que le compte exact n’est pas revalidé.

## Important pour HTTPS

Le `.htaccess` ne remplace pas l’activation du certificat dans IONOS. Activez le certificat **avant** de forcer la redirection. Le HSTS est volontairement réglé à un jour pour le premier déploiement ; après une semaine stable, passez à `max-age=31536000`.
