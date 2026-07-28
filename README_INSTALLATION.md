# PASS50 Collecteur de métriques V1

## Ce qui fonctionne réellement

### YouTube
À partir du lien officiel déjà enregistré dans chaque fiche, PASS50 récupère :

- identifiant stable de la chaîne ;
- abonnés publics ;
- vues cumulées ;
- nombre de vidéos ;
- douze dernières vidéos ;
- vues, likes et commentaires de chaque vidéo ;
- date de publication ;
- évolution entre les collectes.

### X
Le collecteur est prêt pour :

- abonnés ;
- nombre de publications ;
- likes ;
- réponses ;
- reposts ;
- citations.

Il devient actif dès qu’un Bearer Token X valide est configuré.

### Calcul automatique
Après chaque collecte, PASS50 calcule les critères disponibles, les cinq périodes et publie les scores dans `app_state`.

Les critères indisponibles ne reçoivent pas zéro. Leur absence réduit la couverture et la confiance.

## Installation

```bash
python3 apply_metrics_v1.py /chemin/vers/pass50-site
```

Importer ensuite :

```text
migration-metrics-v1.sql
```

Dans le fichier `api/config.php` conservé uniquement sur le serveur IONOS, renseigner les clés dans la section `metrics` :

```php
'metrics' => [
    'PASS50_YOUTUBE_API_KEY' => 'VOTRE_CLE_GOOGLE',
    'x_bearer_token' => '',
],
```

## Obtenir la clé YouTube

1. Créer ou ouvrir un projet Google Cloud.
2. Activer YouTube Data API v3.
3. Créer une clé API.
4. Restreindre la clé à YouTube Data API v3 et aux adresses IP du serveur lorsque possible.
5. Enregistrer la clé uniquement dans `api/config.php` sur le serveur IONOS. Ce fichier est exclu du déploiement Git.

## Collecte manuelle

Après publication :

```text
Administration → Métriques → Collecter maintenant
```

## Collecte automatique

Les workflows GitHub Actions P0, P1 et P2 appellent `api/metrics-cron.php` en
`POST` avec une signature HMAC. Configurer uniquement les secrets GitHub
`PASS50_METRICS_CRON_URL` et `PASS50_METRICS_CRON_SECRET`, puis activer
explicitement `metrics.orchestrator_enabled` côté serveur.

Un cron IONOS pourra appeler le même contrat HTTP. Les appels `GET`, les jetons
dans l’URL et les secrets de plateforme transmis par le planificateur sont
refusés.

## Limites actuelles

Instagram, Facebook et TikTok n’autorisent pas un accès complet aux statistiques de comptes tiers à partir d’un simple lien. Ils seront ajoutés avec :

- application Meta et connexion des comptes créateurs/professionnels ;
- accès TikTok Research API ou connexion OAuth du créateur.

Le paquet ne contourne pas les protections des plateformes et ne fait pas de scraping fragile.
