# TikTok OAuth PASS50 — Bac à sable

## URI de redirection

`https://www.pass50.store/api/tiktok-oauth-callback.php`

## Configuration privée IONOS

Ajouter uniquement dans `api/config.php` sur le serveur, jamais dans GitHub :

```php
'tiktok_oauth' => [
    'client_key' => 'CLE_CLIENT_DU_BAC_A_SABLE',
    'client_secret' => 'SECRET_CLIENT_DU_BAC_A_SABLE',
    'redirect_uri' => 'https://www.pass50.store/api/tiktok-oauth-callback.php',
    'environment' => 'sandbox',
    'token_encryption_key' => $config['google_oauth']['token_encryption_key'] ?? '',
],
```

La clé de chiffrement peut être la même clé AES-256-GCM déjà utilisée par les connecteurs YouTube et Meta.

## Portées attendues

- `user.info.basic`
- `user.info.profile`
- `user.info.stats`
- `video.list`

## Test

1. Se connecter à PASS50.
2. Ouvrir « Mon espace ».
3. Cliquer sur « Connecter TikTok ».
4. Autoriser les quatre portées avec un utilisateur cible du Bac à sable.
5. Vérifier le retour vers PASS50, le profil, les statistiques et les vidéos récentes.
6. Tester la déconnexion et la révocation.

Le connecteur est strictement en lecture seule et ne modifie jamais `app_state` ni le classement public.
