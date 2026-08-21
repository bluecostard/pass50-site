# PASS50 — Coque native (Capacitor)

Coque **iOS / Android** autour du client mobile déjà en prod :
`https://pass50.store/app.html`

Doctrine :
- le **site desktop** reste le produit complet ;
- `/app.html` = client API mobile ;
- cette coque = WebView native + splash/status bar pour les stores.

## Prérequis

- Node 20+
- Android Studio (Play)
- Xcode + compte Apple Developer (App Store) — **macOS uniquement** pour `ios/`

## Première installation

```bash
cd shell
npm install
npm run add:android   # génère ./android (Linux/macOS)
npm run add:ios       # génère ./ios (macOS seulement)
npm run sync
```

Ouvrir les projets :

```bash
npm run open:android
npm run open:ios
```

## Identifiants

| Clé | Valeur |
|---|---|
| App ID / bundle | `store.pass50.app` |
| Nom | `PASS50` |
| URL chargée | `https://pass50.store/app.html?source=native` |
| Contrat splash | `PASS50-NATIVE-SHELL-V1` |

## Deep links / Universal Links

Fichiers servis à la racine du site :

- `/.well-known/assetlinks.json` — Android (remplacer `REPLACE_WITH_PLAY_APP_SIGNING_SHA256`)
- `/.well-known/apple-app-site-association` — iOS (remplacer `TEAMID`)

Après signature Play / Apple, mettre à jour ces empreintes puis redéployer.

## Soumission stores (résumé)

1. Icônes / splash natifs dans Android Studio / Xcode (sources `www/icon-512.png`).
2. Privacy Policy : `https://pass50.store/politique-confidentialite.html`
3. Remplir la fiche store (FR) : classement influenceurs, fil, lives, compte.
4. Build release signé → Play Console / App Store Connect.
5. Apple 4.2 : la coque charge une app réelle (auth, classement, fil, live) — pas une simple page marketing.

## Notes

- Les dossiers `android/` et `ios/` sont **générés localement** (ignorés par git) pour rester légers.
- Changer l’URL distante uniquement dans `capacitor.config.json` → `npm run sync`.
