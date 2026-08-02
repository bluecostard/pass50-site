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

## Trust Gate équilibré (anti lives terminés)

Module dédié `api/live-radar-v4-trust.php` + `live-trust-gate-v1.js`.

Publication publique uniquement si :
- la dernière sonde est explicitement `live` (pas `unknown`) ;
- la confirmation a moins de **12 min** (TikTok), **15 min** (Instagram/Facebook) ou **20 min** (YouTube).

Ces fenêtres restent **au-dessus** de l’intervalle de balayage, sinon la liste se vide.

Un blocage `unknown` **ne maintient pas** le LIVE public. Offline/replay → retrait immédiat. Grâce serveur de retest un peu plus longue (18–25 min).

TikTok : API stricte, preuve croisée, **ou** salle API fraîche peuvent confirmer ; le Trust Gate coupe ensuite les fantômes.

Au clic sur **Regarder**, ouverture immédiate puis vérification en arrière-plan.
