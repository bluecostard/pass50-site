# PASS50 Mobile (Expo)

Application native **iOS / Android** (écrans React Native, pas une WebView).

Le **design** reprend le client mobile du site (`app.html`) : fond `#050705`, lime `#b7ff00`, dock flottant Classement · Fil · Live · Compte.

Le **fonctionnement** reste une vraie app : onglets natifs, API `https://pass50.store/api/`, pas de navigation multi-pages web.

Stack : Expo SDK 57 · Expo Router · API PASS50

## Développement local

```bash
cd apps/mobile
npm install
cp .env.example .env   # optionnel — défaut prod
npm start
```

Scan QR avec Expo Go, ou `npm run ios` / `npm run android` avec simulateur.

## EAS Build (stores)

**Pas besoin d’installer `eas-cli` en global.** Le projet l’inclut déjà (`devDependencies`) — utilise `npm run` ou `npx eas` depuis `apps/mobile`.

### 1. Lier le projet Expo (une fois)

```bash
cd apps/mobile
npm install
npm run eas:login
npm run eas:init    # crée projectId dans app.json → extra.eas.projectId
```

Équivalent sans scripts npm :

```bash
npx eas login
npx eas init
```

> Si `npm install -g eas-cli` échoue avec **EACCES** sur Mac, c’est normal sans `sudo` — **n’utilise pas `-g`**, reste sur `npm run` / `npx eas` ci-dessus.

### 2. Builds

| Profil | Usage | Commande |
|--------|--------|----------|
| `preview` Android | APK interne | `npm run eas:preview:android` |
| `preview` iOS | Install iPhone (compte Apple Developer) | `npm run eas:preview:ios` |
| `production` | Play Store / App Store | `npm run eas:production` |
| `development` | Simulateur iOS (Mac) | `npx eas build --platform ios --profile development` |

### Tester sur iPhone **sans** build store (Expo Go)

1. Installe **Expo Go** depuis l’App Store (même SDK que le projet : Expo 57).
2. Sur le Mac, même Wi‑Fi que l’iPhone :

```bash
cd ~/pass50-site/apps/mobile
npm start
```

3. Scanne le QR code avec l’appareil photo iPhone → ouvre dans Expo Go.

**Timeout / `192.168.x.x` inaccessible ?** Utilise le tunnel (évite install globale — `@expo/ngrok` est déjà dans le projet) :

```bash
cd ~/pass50-site/apps/mobile
git pull
npm install
npm run start:tunnel
```

Scanne le nouveau QR (URL `…exp.direct…`, pas une IP locale). Ne réponds **pas** « yes » à une install **globale** de ngrok — si Expo la propose, annule et relance après `npm install`.

C’est le moyen le plus rapide de valider l’UI. Pour une app installable hors Expo Go (TestFlight / icône PASS50), il faut un **compte Apple Developer** (~99 $/an) puis `npm run eas:preview:ios`.

Variables d’environnement de build : `EXPO_PUBLIC_API_BASE` (définie dans `eas.json`).

### 3. Soumission stores

```bash
npm run eas:submit:android
npm run eas:submit:ios
```

Prérequis :
- **Android** : compte Google Play + clé de service (JSON) configurée dans EAS credentials
- **iOS** : compte Apple Developer, certificats gérés par EAS (`eas credentials`)

## Codemagic (App Store / TestFlight / Play)

Alternative CI/CD cloud : fichier `codemagic.yaml` à la **racine** du dépôt.

### Workflows

| Workflow | Rôle |
|----------|------|
| `pass50-ios-testflight` | Build IPA + upload **TestFlight** |
| `pass50-ios-appstore` | Build IPA + upload App Store Connect (revue manuelle par défaut) |
| `pass50-android-internal` | Build AAB + piste Play **internal** (draft) |

### Configuration Codemagic (une fois)

1. [codemagic.io](https://codemagic.io) → **Add application** → repo `bluecostard/pass50-site`
2. **Team settings → Integrations → Apple Developer Portal**  
   Créer une clé API App Store Connect nommée exactement **`Pass50`**
3. **Environment groups** → groupe **`pass50_store`** :
   - `APP_STORE_APPLE_ID` = ID numérique de l’app (App Store Connect → infos app)
4. **Code signing identities** → iOS distribution pour `store.pass50.app`
5. Créer l’app dans [App Store Connect](https://appstoreconnect.apple.com) (Bundle ID `store.pass50.app`)
6. Scanner la branche (`main` ou `cursor/pass50-mobile-app-0a21`) → **Start build** → `pass50-ios-testflight`

Android (optionnel) : groupe `google_play` + variable secrète `GOOGLE_PLAY_SERVICE_ACCOUNT_CREDENTIALS`, identity keystore `pass50_android`.

Le prebuild Expo (`ios/` / `android/`) est généré **sur la VM** à chaque build — ne pas committer ces dossiers.

### Identifiants (alignés coque Capacitor)

| Clé | Valeur |
|-----|--------|
| Bundle ID iOS / package Android | `store.pass50.app` |
| Nom affiché | `PASS50` |
| Schéma deep link | `pass50://` |
| Universal links | `pass50.store` / `www.pass50.store` |

Mettre à jour `/.well-known/assetlinks.json` (SHA-256 Play) et `apple-app-site-association` (TEAMID) après la 1ʳᵉ build signée.

Politique de confidentialité : https://pass50.store/politique-confidentialite.html

## Migration depuis `shell/` (Capacitor)

| Avant (Capacitor) | Après (Expo) |
|-------------------|--------------|
| Design `app.html` (WebView Capacitor) | Même design, écrans natifs Expo |
| `shell/capacitor.config.json` | `apps/mobile/app.json` |
| `npm run sync` + Android Studio | `eas build` |
| Même package `store.pass50.app` | Même package — mise à jour store possible |

La coque Capacitor reste dans `shell/` le temps de basculer les builds store ; ne plus l’utiliser pour les nouvelles features.

## Tests

```bash
python3 -m unittest tests.test_pass50_mobile_app_v1 tests.test_pass50_mobile_eas_v1 -v
```
