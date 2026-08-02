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

## Trust Gate (anti lives terminés)

Module dédié `api/live-radar-v4-trust.php` + `live-trust-gate-v1.js`.

Publication publique uniquement si :
- la dernière sonde est explicitement `live` ;
- la confirmation a moins de **90 s** (TikTok), **120 s** (Instagram/Facebook) ou **240 s** (YouTube).

Un blocage `unknown` **ne maintient plus** le LIVE dans la liste publique. Le serveur peut encore retester pendant une grâce courte (8–15 min), mais l’utilisateur ne voit que des directs frais.

TikTok : une salle « fraîche » seule ne publie plus — il faut une API stricte ou une preuve croisée API+HTML.

Au clic sur **Regarder**, le client revérifie le profil avant d’ouvrir ; si le direct est mort, il est retiré immédiatement.
