# PASS50 V12 — Étapes 1 et 2 fusionnées

## Étape 1
- 7 FI persistantes côté navigateur et MySQL : les 6 profils approuvés + SmOokii Gamer.
- Base de 127 : résultat attendu 134.
- LIVE sans confirmation : expiration 8 h ; sans date : 10 min.
- Liens vidéo exacts cliquables dans la FI.
- Sauvegardes Réseaux + Actualité sérialisées.

## Étape 2
- Moteur 15 critères branché au Data Engine existant.
- Calcul après Collecter, Publier et cron.
- Scores écrits dans `profile.scores` pour 2H, 24H, 48H, 7J et 15J.
- Couverture, confiance, nombre de critères et explication affichés dans la FI.
- Données manquantes ignorées, sans attribution d’un faux zéro.
- Résultats conservés dans `p50_algorithm_scores`.

## Déploiement
1. Remplacer le dépôt par le contenu du ZIP.
2. Importer `migration-data-engine-v1.sql` si la migration n’a jamais été appliquée. La table algorithmique est aussi créée automatiquement.
3. Ouvrir PASS50 connecté comme propriétaire.
4. Administration → Données : Collecter puis Publier.
5. Recharger la page.

## Contrôles
Dans la console :
```javascript
PASS50_STEP12_STATUS
```
Résultat attendu sur une base à 127 : `present: 7`, `total: 134`.

Une FI affiche désormais Confiance, Couverture, Critères mesurés et Moteur 15 critères.
