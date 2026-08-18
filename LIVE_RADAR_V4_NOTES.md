# PASS50 Radar LIVE V4

Cette version stabilise la chaîne de données LIVE sans modifier le classement public.

## Chaîne couverte

1. lien officiel vérifié ;
2. sondes multi-plateformes ;
3. qualification `live`, `probable`, `replay`, `offline` ou `unknown` ;
4. stockage durable dans `p50_live_streams` et `p50_live_source_health` ;
5. publication des seuls directs confirmés ;
6. conservation des candidats et replays dans l’historique.

## TikTok

- API officielle publique quand elle répond ;
- sondes HTML indépendantes en secours ;
- deux preuves HTML cohérentes peuvent confirmer un live ;
- une seule preuve reste `probable` et n’est pas publiée ;
- un challenge anti-bot reste `unknown` et ne clôt pas un live confirmé récent.

## YouTube

- `isLiveNow` confirme un live ;
- une date de fin explicite classe le contenu en replay ;
- l’ID de la vidéo et l’URL exacte sont conservés.

## Publish UTC (2026-08-03)

Bug critique : `strtotime()` sur datetime MySQL UTC était interprété en heure locale IONOS,
donc les LIVE fraîchement stockés étaient rejetés par le Trust Gate PHP (liste publique vide
alors que le scan trouvait des directs). Parse UTC explicite + dismiss admin TTL 24h.

## Probe Recovery (2026-08-03)

- Referer/Origin natifs + UA Chrome 126 + retry mobile si challenge
- Meta/TikTok « page lisible sans signal live » → `offline` (plus `unknown`)
- Quick scan : réserver 4–6 slots de découverte ; priority live seulement
- TikTok : 2 HTML sur la même salle fraîche peuvent confirmer

## Trust Gate équilibré (anti lives terminés)

Module dédié `api/live-radar-v4-trust.php` + `live-trust-gate-v1.js`.

Publication publique uniquement si :
- la dernière sonde est explicitement `live` (pas `unknown`) ;
- la confirmation a moins de **12 min** (TikTok), **15 min** (Instagram/Facebook) ou **20 min** (YouTube).

Ces fenêtres restent **au-dessus** de l’intervalle de balayage, sinon la liste se vide.

Un blocage `unknown` **ne maintient pas** le LIVE public. Offline/replay → retrait immédiat. Grâce serveur de retest un peu plus longue (18–25 min).

TikTok : API stricte, preuve croisée, **ou** salle API fraîche peuvent confirmer ; le Trust Gate coupe ensuite les fantômes.

Outil de travail **`webcast.tiktok.com/webcast/room/info_by_user`** : sonde P0 IONOS et audit GitHub des `unknown`. Un embed TikTok seul (sans JSON live) ne doit plus classer un direct en `offline`.

Toutes les **3 h**, GitHub trie les sources `unknown` TikTok / YouTube / Facebook vraiment en live, les publie si la preuve est stricte, et les ajoute à la watchlist P0 dynamique s’ils n’y sont pas déjà. Résultats inscrits dans `pass50/discussions/radar-unknown-audit.md`. Arrêt : setting `live_radar_v4_unknown_audit_enabled=false` ou désactiver le workflow.

Au clic sur **Regarder**, ouverture immédiate puis vérification en arrière-plan.
