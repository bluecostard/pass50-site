# PASS50 — Coque native (Capacitor)

> **Successeur :** l’app Expo dans [`apps/mobile/`](../apps/mobile/README.md) reprend le **design** mobile du site en écrans natifs (pas une WebView).
> Cette coque Capacitor reste pour transition ; ne plus l’enrichir pour les nouvelles features.

Coque **iOS / Android** autour du client mobile déjà en prod :
`https://pass50.store/app.html`

Doctrine :
- le **site desktop** reste le produit complet ;
- `/app.html` = client API mobile ;
- cette coque = WebView native + splash/status bar pour les stores.

## Prérequis

- Node 20+
- Android Studio (Play) — projet `android/` déjà généré dans ce dépôt
- Xcode + compte Apple Developer (App Store) — **macOS uniquement** pour `ios/`

## Android (prêt à ouvrir)

```bash
cd shell
npm install
npm run sync
npm run open:android
```

Dans Android Studio : Build → Generate Signed Bundle / APK → Play Console.

App Links : `https://pass50.store/app.html` (+ schéma `pass50://app`).
Après signature Play, coller le SHA-256 dans `/.well-known/assetlinks.json`.

## iOS (à générer sur Mac)

```bash
cd shell
npm install
npm run add:ios
npm run sync
npm run open:ios
```

## Identifiants

| Clé | Valeur |
|---|---|
| App ID / bundle | `store.pass50.app` |
| Nom | `PASS50` |
| URL chargée | `https://pass50.store/app.html?source=native` |
| Contrat splash | `PASS50-NATIVE-SHELL-V1` |
| versionName | `1.0` |

## Deep links / Universal Links

Fichiers servis à la racine du site :

- `/.well-known/assetlinks.json` — Android (remplacer `REPLACE_WITH_PLAY_APP_SIGNING_SHA256`)
- `/.well-known/apple-app-site-association` — iOS (remplacer `TEAMID`)

## Soumission stores (résumé)

1. Icônes / splash natifs dans Android Studio / Xcode (sources `www/icon-512.png`).
2. Privacy Policy : `https://pass50.store/politique-confidentialite.html`
3. Remplir la fiche store (FR) : classement influenceurs, fil, lives, compte.
4. Build release signé → Play Console / App Store Connect.
5. Apple 4.2 : la coque charge une app réelle (auth, classement, fil, live) — pas une simple page marketing.

## Notes

- `android/` est versionné ; `ios/` reste local (Mac).
- Builds Gradle / `node_modules` restent ignorés.
- Changer l’URL distante uniquement dans `capacitor.config.json` → `npm run sync`.
