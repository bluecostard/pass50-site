# PASS50 — Étapes 1 + 2 et bouton MAJ PASS50

## Installation

Remplacer le contenu du dépôt/hébergement par ce projet complet. Ne pas téléverser le ZIP comme un fichier unique : décompresser puis publier tous les fichiers en conservant les dossiers.

## Utilisation

1. Ouvrir PASS50 et se connecter comme propriétaire.
2. Ouvrir Administration.
3. Sélectionner **MAJ PASS50** entre LIVE et Data Hub.
4. Cliquer sur **LANCER LA MAJ PASS50**.
5. Garder la page ouverte jusqu'au message de fin.

Le bouton exécute : synchronisation, collecte de toute la base par lots de 5, calcul des 15 critères, écriture des scores, reclassement, publication et capture.

## Contrôle

Dans la console :

```javascript
PASS50_STEP12_STATUS
PASS50Maj.status()
PASS50_MAJ_STATUS
```

Le total attendu dans la base déjà portée à 134 reste **134 FI**. L'import est dédupliqué.
