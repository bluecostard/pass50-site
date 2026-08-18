# Discussion PASS50 — Audit unknown radar

Contrôle régulier des lives classés `unknown`. Chaque passage (toutes les 3 h) s’écrit **en haut** de ce journal.

- Outil TikTok : `webcast.tiktok.com/webcast/room/info_by_user`
- YouTube : `isLiveNow` · Facebook : signal live public si la page n’est pas bloquée
- Les salles terminées ne sont pas poussées
- Un compte déjà en P0 n’est pas recopié dans la liste

Pour arrêter la boucle : le dire dans le chat PASS50.

<!-- JOURNAL:BEGIN -->

### 2026-08-18 14:16 UTC

- Unknown sondés : **183**
- Vraiment en live : **1**
- Publiés radar : **1**
- Ajoutés P0 : **1**

Vraiment en live :
- TikTok `hamondchic` @coachhamond — Allô yougoss · 10507 viewers

Nouveaux P0 :
- TikTok `hamondchic`

### 2026-08-18 10:52 UTC

- Unknown sondés : **176**
- Vraiment en live : **0**
- Publiés radar : **0**
- Ajoutés P0 : **0**
- Aucun unknown réellement en live à ce passage.

### 2026-08-18 08:04 UTC

- Unknown sondés : **138**
- Vraiment en live : **0**
- Publiés radar : **0**
- Ajoutés P0 : **0**
- Aucun unknown réellement en live à ce passage.

### 2026-08-18 05:11 UTC

Collecte live 3 h — résultats complets (cron unknown 05:03 + balayages radar + sondes webcast).

Audit unknown (`live-radar-unknown-audit.php`) :
- Erreur : `GET HTTP 404` — endpoint absent sur IONOS (`No input file specified.`), aucun unknown listé côté prod
- Unknown sondés via l’API : **0**
- Webcast indépendant (12 P0 TikTok + Observateur Ébène) : **13** comptes, **0** salle `status=2`

Balayage radar LIVE 05:02–05:07 UTC (`github_32101356382_1`) :
- Sources : **475/475** · classifiées **235** · unknown **240**
- Vraiment en live : **1**
- Publiés radar : **0**
- Replay : **1**
- Probables : **0**

Vraiment en live (05:06:50 UTC, non poussé dans `liveStreams` publics) :
- YouTube `census-observateur-ebene` Observateur Ébène — `isLiveNow` radar conf 99 · https://www.youtube.com/@Observateur/live
  - Contrôle webcast/HTML 05:11 : `isLiveNow=false` · videoId `EGCaAq-4Bvg` · titre `OBSERVATEUR-` (direct déjà terminé ou page replay)

Replay (non publié) :
- YouTube `kevine` Kévine Obin — `known_false_positive`

États 05:07 :
- TikTok : 131 offline
- YouTube : 1 live · 1 replay · 74 offline · 15 unknown
- Facebook : 28 offline · 90 unknown
- Instagram : 135 unknown

P0 TikTok webcast 05:11 (tous hors live, HTTP 200, salle vide) :
- `apoutchou` @apoutchou_national1
- `general-camille-makosso` @generalmakossocamille79
- `census-no-limit` @nolimit_vousdv
- `census-amour-ruth-poopy` @amourruth0
- `census-jordan-evraa` @realjordanevraa
- `dbz` @dbz.07
- `maabio` @biodetoxminceur
- `census-el-profesor` @elprofesor_off
- `census-adjinaya-el-professor` @elprofesor.off
- `p_1785175190809` @ulrich_jordan30
- `census-sarara-messan` @sarra_messan
- `louissette` @misscadic
- `census-observateur-ebene` @observateur_ebene

Fenêtre 3 h précédente — balayage 03:08–03:12 UTC (`github_32094366805_1`) :
- Vraiment en live : **2**
- Publiés radar : **1**

Vraiment en live à 03:12 :
- TikTok `aya-robert` @aya.robert27 — Aya Robert est en direct · 566–571 viewers · room `7675136586312370976` · https://www.tiktok.com/@aya.robert27/live
- YouTube `census-observateur-ebene` Observateur Ébène — conf 99

Statut Aya Robert au passage 05:03 : `tiktok_live_ended` (radar) · webcast `status_code=30003` (05:11, hors live)

Nouveaux P0 (seed radar, rescan ~2 min) :
- TikTok `aya-robert` @aya.robert27
- YouTube `census-observateur-ebene` @Observateur

### 2026-08-18 05:03 UTC

Erreur : `GET HTTP 404`

### 2026-08-18 00:47 UTC

Contrôle webcast des 11 comptes P0 déjà en liste (avant le premier cron prod).

- Unknown sondés : **11**
- Vraiment en live : **1**
- Publiés radar : **0**
- Ajoutés P0 : **0**

Vraiment en live :
- TikTok `census-jordan-evraa` @realjordanevraa — Goumin tv, causerie ou Q/A · 141 viewers
