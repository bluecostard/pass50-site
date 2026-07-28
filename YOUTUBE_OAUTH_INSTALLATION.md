# PASS50 — Installation OAuth YouTube

Cette intégration connecte une chaîne YouTube à un compte PASS50 avec des droits de lecture uniquement. Les jetons OAuth sont chiffrés avant leur enregistrement dans MySQL.

## 1. Fichiers déployés

- `api/youtube-oauth-start.php` : crée immédiatement une demande d’autorisation signée à partir du jeton de session présenté, sans aucune lecture, migration ni écriture MySQL avant l’ouverture de Google.
- `api/youtube-oauth-callback.php` : reçoit le retour Google, vérifie le navigateur et la session PASS50, puis enregistre la chaîne autorisée.
- `api/youtube-oauth-status.php` : retourne uniquement l’état public de la connexion, jamais les jetons.
- `api/youtube-oauth-disconnect.php` : révoque l’autorisation Google et supprime la connexion locale.
- `migration-youtube-oauth-v1.sql` : crée automatiquement les tables nécessaires lors du callback.

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

Les trois endpoints JSON utilisent le jeton de session PASS50 dans l’en-tête `Authorization: Bearer ...`.

1. Envoyer `POST /api/youtube-oauth-start.php`.
2. Rediriger l’utilisateur vers la valeur `authorizationUrl` reçue.
3. Après le retour Google, lire `GET /api/youtube-oauth-status.php`.
4. Pour déconnecter la chaîne, envoyer `POST /api/youtube-oauth-disconnect.php`.

Le jeton de session n’est jamais placé dans une URL. Le démarrage hache ce jeton et l’intègre dans un état OAuth signé, valable dix minutes. Un cookie `Secure`, `HttpOnly` et `SameSite=Lax` lie également cet état au navigateur qui a lancé la connexion. Le domaine du cookie couvre `pass50.store` et `www.pass50.store` lorsque ces deux hôtes sont configurés.

Aucune requête MySQL n’est exécutée dans `youtube-oauth-start.php`. La session est résolue uniquement dans le callback, après le retour de Google. Les tables OAuth restent créées de façon idempotente avant l’enregistrement chiffré de la connexion.
