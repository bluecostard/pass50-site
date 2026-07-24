# PASS50 — Bouton unique MAJ PASS50

Cette version conserve les étapes 1 et 2 et ajoute dans Administration un onglet **MAJ PASS50** entre LIVE et Data Hub.

Le bouton exécute automatiquement :

1. Synchronisation des FI vers le registre serveur.
2. Collecte de toute la base par lots de 5.
3. Conservation des preuves et calcul des 15 critères à chaque lot.
4. Publication des scores calculés.
5. Rechargement et reclassement.
6. Synchronisation finale.
7. Capture du classement.

Le traitement affiche une progression et un statut final. La page doit rester ouverte pendant l'exécution.

Contrôle console :

```javascript
PASS50Maj.status()
PASS50_MAJ_STATUS
PASS50_STEP12_STATUS
```
