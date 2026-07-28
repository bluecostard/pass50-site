# PASS50 — Installation OAuth YouTube

Cette intégration connecte une chaîne YouTube à un compte PASS50 avec des droits de lecture uniquement. Les jetons OAuth sont chiffrés avant leur enregistrement dans MySQL.

## 1. Fichiers déployés

- `api/youtube-oauth-start.php` : crée immédiatement une demande d’autorisation signée à partir du jeton de session présenté, sans aucune lecture, migration ni écriture MySQL avant l’ouverture de Google.
- `api/youtube-oauth-callback.php` : reçoit le retour Google, vérifie le navigateur et la session PASS50, puis enregistre la chaîne autorisée.
- `api/youtube-oauth-status.php` : retourne uniquement l’état public de la connexion, jamais les jetons.
- `api/youtube-oauth-disconnect.php` : révoque l’autorisation Google et supprime la connexion locale.
- `api/youtube-analytics-summary.php` : lit et conserve un résumé privé YouTube Analytics pour l’utilisateur connecté.
- `migration-youtube-oauth-v1.sql` : crée automatiquement les tables OAuth nécessaires lors du callback.
- `migration-youtube-analytics-v1.sql` : crée le stockage privé des captures Analytics.

## 2. Configuration privée sur IONOS

Éditer uniquement le fichier serveur `api/config.php`. Ne jamais copier les vraies valeurs dans GitHub.

Ajouter ce bloc au tableau de configuration :

```php
'google_oauth' => [
    'client_id' => 'VOTRE_CLIENT_ID_GOOGLE',
    'client_secret' => 'VOTRE_CLIENT_SECRET_GOOGLE',
    'redirect_uri' => 'https://www.pass50.store/api/youtube-oauth-callback.php',
    'token_encryption_key' => 'VOTRE_CLE_BASE64_DE_32_OCTETS',
],
```

L’URI de redirection doit rester strictement identique à celle enregistrée dans Google Cloud.

## 3. Générer la clé de chiffrement

Depuis un terminal disposant de PHP :

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Conserver cette clé avec les secrets serveur. La perdre rendrait les jetons déjà enregistrés illisibles. La publier compromettrait leur confidentialité.

## 4. Autorisations demandées

```text
https://www.googleapis.com/auth/youtube.readonly
https://www.googleapis.com/auth/yt-analytics.readonly
```

Aucune autorisation d’écriture, de gestion de chaîne ou de lecture des revenus n’est demandée.

## 5. Appels depuis l’interface PASS50

Les endpoints JSON utilisent le jeton de session PASS50 dans l’en-tête `Authorization: Bearer ...`.

1. Envoyer `POST /api/youtube-oauth-start.php`.
2. Rediriger l’utilisateur vers la valeur `authorizationUrl` reçue.
3. Après le retour Google, lire `GET /api/youtube-oauth-status.php`.
4. Lire la dernière capture privée avec `GET /api/youtube-analytics-summary.php?days=28`.
5. Actualiser Analytics avec `POST /api/youtube-analytics-summary.php` et le corps `{"days":28}`.
6. Pour déconnecter la chaîne, envoyer `POST /api/youtube-oauth-disconnect.php`.

Le jeton de session n’est jamais placé dans une URL. Le démarrage hache ce jeton et l’intègre dans un état OAuth signé, valable dix minutes. Un cookie `Secure`, `HttpOnly` et `SameSite=Lax` lie également cet état au navigateur qui a lancé la connexion. Le domaine du cookie couvre `pass50.store` et `www.pass50.store` lorsque ces deux hôtes sont configurés.

Aucune requête MySQL n’est exécutée dans `youtube-oauth-start.php`. La session est résolue uniquement dans le callback, après le retour de Google. Les tables OAuth restent créées de façon idempotente avant l’enregistrement chiffré de la connexion.

## 6. Rapport Analytics privé

Le rapport par défaut couvre les 28 derniers jours complets et récupère uniquement des métriques non monétaires : vues, temps de visionnage, durée moyenne, pourcentage moyen regardé, mentions J’aime, commentaires, partages et abonnés gagnés ou perdus.

Les données sont :

- accessibles uniquement au compte PASS50 ayant autorisé la chaîne ;
- enregistrées sans jeton, secret ni réponse brute ;
- limitées à une actualisation toutes les cinq minutes ;
- séparées des captures publiques du classement.

## 7. Règle d’équité

Les statistiques privées OAuth ne modifient aucun score ni rang public. Elles ne sont pas écrites dans `app_state` ni dans les captures canoniques utilisées par le classement expérimental. Une utilisation future nécessitera une règle d’équité explicite, car seules les chaînes volontaires fournissent ces données.
