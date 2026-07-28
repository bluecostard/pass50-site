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

## Continuité

La fenêtre de grâce est de 20 minutes pour TikTok et de 15 minutes pour YouTube, Instagram et Facebook. Elle couvre l’intervalle entre deux balayages serveur sans maintenir indéfiniment un faux direct.
