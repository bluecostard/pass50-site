# SEO PASS50 — socle V1 (2026-08-05)

## En place
- `robots.txt` — index public, exclusion admin / api / private
- `sitemap.php` (via `/sitemap.xml`) — accueil, pronostics, pages légales + **toutes les fiches FI**
- Pages FI publiques SSR : `/fi/{id}` → `fi.php` (meta, OG, Twitter, JSON-LD ProfilePage/Person, contenu crawlable)
- Meta + Open Graph + Twitter sur `index.html` et `pronostics.html`
- JSON-LD Organization + WebSite (accueil) / WebPage (pronostics)
- Liens internes Top 10 → `/fi/{id}` (modal SPA si JS, page publique sinon)
- `noindex` : Mon fil, admin pronostics, live scout, offline, classement fictif

## Après déploiement
1. Resoumettre `https://pass50.store/sitemap.xml` dans Google Search Console
2. Inspecter une URL type `/fi/{id}` (indexation)
3. Vérifier le padlock / HTTPS (déjà en place)

## Prochaine vague
1. Contenu éditorial crawlable (pas uniquement JS) pour le Top / buzz
2. OG image dédiée Pronostics (1200×630)
3. Core Web Vitals (images, fonts, LCP)

## Cibles sémantiques (priorité)
- classement influenceurs ivoiriens
- influenceurs Côte d’Ivoire / diaspora
- buzz TikTok Instagram CI
- pronostics influenceurs (sans argent réel)
