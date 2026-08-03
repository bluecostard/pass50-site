# PASS50 — App iOS (Capacitor V1)

## Décisions
- **Mode A remote** : WebView → `https://pass50.store`
- **iOS uniquement** (Android plus tard)
- **Push en V1** (APNs via `@capacitor/push-notifications`)

## Prérequis machine
- macOS + **Xcode** (compte Apple Developer)
- Node 20+
- CocoaPods (`sudo gem install cocoapods` si besoin)

## Setup une fois
```bash
cd mobile
npm install
npx cap add ios          # première fois seulement
npx cap sync ios
npx cap open ios
```

Dans Xcode :
1. Signing & Capabilities → Team Apple
2. Ajouter **Push Notifications**
3. Ajouter **Associated Domains** : `applinks:pass50.store` et `applinks:www.pass50.store`
4. Bundle ID = `store.pass50.app` (doit matcher `apple-app-site-association` + Apple Developer)
5. Créer une clé APNs (.p8) → renseigner sur le serveur (`api/config.php` → `push`)

## Déploiement site (push + deep links)
Déployer aussi :
- `api/push-devices.php`, `api/push-core.php`, `api/push-send-cron.php`
- `.well-known/apple-app-site-association`
- `mobile-bridge.js` (chargé par le site)

## Tester
1. Run sur iPhone réel (simulateur = pas de push APNs réel)
2. Accepter les notifications
3. Vérifier `POST /api/push-devices.php` en base
4. Deep link : `https://pass50.store/?profile=<id>` depuis Notes

## Associated Domains
Remplacer `TEAMID` dans `.well-known/apple-app-site-association` par votre Team ID Apple (10 caractères), puis redéployer le site.
