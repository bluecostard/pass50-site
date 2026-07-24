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

Dans `api/config.php`, ajouter :

```php
define('PASS50_YOUTUBE_API_KEY', 'VOTRE_CLE_GOOGLE');
define('PASS50_X_BEARER_TOKEN', '');
define('PASS50_METRICS_CRON_TOKEN', 'UNE_CHAINE_LONGUE_ET_ALEATOIRE');
```

## Obtenir la clé YouTube

1. Créer ou ouvrir un projet Google Cloud.
2. Activer YouTube Data API v3.
3. Créer une clé API.
4. Restreindre la clé à YouTube Data API v3 et aux adresses IP du serveur lorsque possible.

## Collecte manuelle

Après publication :

```text
Administration → Métriques → Collecter maintenant
```

## Collecte automatique IONOS

Programmer l’URL suivante toutes les heures :

```text
https://pass50.store/api/metrics-cron.php?token=VOTRE_JETON&limit=20
```

Pour les profils HOT, une fréquence de 15 minutes est recommandée. Pour le reste, une heure suffit.

## Limites actuelles

Instagram, Facebook et TikTok n’autorisent pas un accès complet aux statistiques de comptes tiers à partir d’un simple lien. Ils seront ajoutés avec :

- application Meta et connexion des comptes créateurs/professionnels ;
- accès TikTok Research API ou connexion OAuth du créateur.

Le paquet ne contourne pas les protections des plateformes et ne fait pas de scraping fragile.
