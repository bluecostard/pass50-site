# Sécurité PASS50 — état au 5 août 2026

## Diagnostic actuel

| Point | État |
|--------|------|
| Certificat SSL (Sectigo `*.pass50.store`) | ✅ Valide jusqu’au 17 janv. 2027 |
| `https://pass50.store` | ✅ Répond en HTTP/2 |
| Redirection `http://` → `https://` | ⚠️ À déployer (corrigée dans `.htaccess`) |
| En-têtes HSTS / XSS / clickjacking | ⚠️ À déployer (corrigés dans `.htaccess`) |
| Contenu mixte (HTTP dans page HTTPS) | ✅ Pas de blocage observé |

**Pourquoi Chrome/Safari affichent « Non sécurisé »**  
Souvent parce que l’utilisateur arrive en `http://pass50.store` : le certificat existe, mais **sans redirection forcée** le navigateur reste en HTTP clair.

## Correctif livré dans le dépôt

Fichier `.htaccess` :
1. Redirection 301 `www` → `pass50.store`
2. Redirection 301 HTTP → HTTPS (y compris via `X-Forwarded-Proto`)
3. HSTS `max-age=86400` (1 jour — passer à `31536000` après 1 semaine stable)
4. `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`
5. CSP en **Report-Only** (ne casse pas l’UI ; à durcir ensuite)
6. Blocage HTTP de `install.php`, `config.php`, `.env`, `.sql`, dossiers `private/`, etc.

API (`api/bootstrap.php`) : mêmes en-têtes de base + HSTS si HTTPS.

## Déploiement IONOS (obligatoire)

1. Pousser / téléverser le nouveau `.htaccess` à la **racine web** `pass50.store`
2. Vérifier :
   ```bash
   curl -sI http://pass50.store/ | head -5
   # attendu : HTTP/1.1 301 → https://pass50.store/
   curl -sI https://pass50.store/ | rg -i 'strict-transport|x-frame|x-content'
   ```
3. Ouvrir le site en navigation privée : cadenas 🔒
4. Après 7 jours stables, monter HSTS :
   `Strict-Transport-Security "max-age=31536000; includeSubDomains"`

## Si la redirection ne prend pas chez IONOS

Dans le panneau IONOS → Domaines → SSL :
- certificat actif sur `pass50.store` **et** `www.pass50.store`
- option « Forcer HTTPS » si proposée (complète le `.htaccess`)

`mod_rewrite` et `mod_headers` doivent être actifs (standard IONOS Apache).

## Prochaine priorité code (après HTTPS)

Le jeton Bearer est encore dans `localStorage` → sensible au XSS.  
Évolution recommandée : cookie de session `Secure` + `HttpOnly` + `SameSite=Lax`.

Puis CSP stricte (retirer `unsafe-inline` en sortant les scripts inline).
