# PASS50 Mobile (Expo)

Application native **iOS / Android** — remplace la coque Capacitor (`shell/`) qui chargeait `app.html` en WebView.

Stack : Expo SDK 57 · Expo Router · API `https://pass50.store/api/`

## Développement local

```bash
cd apps/mobile
npm install
cp .env.example .env   # optionnel — défaut prod
npm start
```

Scan QR avec Expo Go, ou `npm run ios` / `npm run android` avec simulateur.

## EAS Build (stores)

### 1. Lier le projet Expo (une fois)

```bash
npm install -g eas-cli
cd apps/mobile
eas login
eas init   # crée projectId dans app.json → extra.eas.projectId
```

### 2. Builds

| Profil | Usage | Commande |
|--------|--------|----------|
| `preview` | APK interne Android, test équipe | `eas build --platform android --profile preview` |
| `production` | Play Store / App Store | `eas build --platform all --profile production` |
| `development` | Simulateur iOS / dev interne | `eas build --platform ios --profile development` |

Variables d’environnement de build : `EXPO_PUBLIC_API_BASE` (définie dans `eas.json`).

### 3. Soumission stores

```bash
eas submit --platform android --profile production
eas submit --platform ios --profile production
```

Prérequis :
- **Android** : compte Google Play + clé de service (JSON) configurée dans EAS credentials
- **iOS** : compte Apple Developer, certificats gérés par EAS (`eas credentials`)

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
| WebView → `app.html` | UI React Native native |
| `shell/capacitor.config.json` | `apps/mobile/app.json` |
| `npm run sync` + Android Studio | `eas build` |
| Même package `store.pass50.app` | Même package — mise à jour store possible |

La coque Capacitor reste dans `shell/` le temps de basculer les builds store ; ne plus l’utiliser pour les nouvelles features.

## Tests

```bash
python3 -m unittest tests.test_pass50_mobile_app_v1 tests.test_pass50_mobile_eas_v1 -v
```
