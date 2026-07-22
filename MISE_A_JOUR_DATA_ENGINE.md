# PASS50 — Moteur de données, socle v1

## Déploiement

1. Décompresser le ZIP.
2. Déposer son contenu à la racine du dépôt GitHub en remplaçant les fichiers existants.
3. Attendre le déploiement GitHub Actions, puis recharger PASS50 avec `Cmd + Shift + R` ou `Ctrl + Shift + R`.

Aucune modification de `index.html` n’est incluse : la mise à jour conserve donc strictement le design actuellement en production.

## Première utilisation

1. Se connecter avec le compte **Propriétaire**.
2. Ouvrir **Administration → Data Hub**.
3. Cliquer sur **Synchroniser les profils**.
4. Cliquer sur **Collecter 5 profils**.
5. Utiliser **Réseaux** pour confirmer manuellement les URL officielles.
6. Utiliser **Naissance** pour ajouter deux sources distinctes confirmant la même date.

Les tables MySQL sont créées automatiquement lors du premier accès au Data Hub. Le fichier `migration-data-engine-v1.sql` est fourni comme solution de secours pour phpMyAdmin.

## Règles intégrées

- Confiance **≥ 90 %** : donnée publiée.
- Confiance **< 90 %** : donnée ignorée publiquement.
- Date de naissance : deux sources distinctes et concordantes obligatoires.
- Réseau social : confirmation explicite du propriétaire ou preuve automatique suffisamment fiable.
- Photo : aucune publication ou copie automatique sans validation humaine.

## Collecteurs actifs

- Wikidata : identité structurée, date de naissance, liens sociaux disponibles.
- Wikipédia : seconde confirmation possible de la date de naissance.
- YouTube : dernières vidéos via la chaîne officielle vérifiée.
- Liens saisis manuellement : validation du domaine, du type de lien et confirmation du propriétaire.
- Historique : captures du classement pour les futures rubriques Records et Les Coulés.

## Fichiers principaux

- `api/data-engine-core.php`
- `api/data-hub.php`
- `api/data-collect.php`
- `api/social-links.php`
- `api/facts.php`
- `api/data-publish.php`
- `api/data-snapshot.php`
- `api/data-history.php`
- `api/activity.php`
- `api/data-cron.php`
- `data-engine-ui.js`
- `data-engine-ui.css`
