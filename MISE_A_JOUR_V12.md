# PASS50 V12 — LIVE réel

## Ajouts
- bouton LIVE cliquable avec compteur ;
- fenêtre des directs réellement actifs ;
- badge LIVE appliqué uniquement aux influenceurs concernés ;
- expiration automatique des directs ;
- onglet Administration > LIVE pour activer/arrêter un direct ;
- endpoint public `api/live-status.php` rafraîchi toutes les 60 secondes ;
- script optionnel de détection YouTube `api/live-check-youtube.php`.

## Détection automatique
YouTube peut être contrôlé automatiquement avec une clé YouTube Data API gratuite et une tâche cron IONOS toutes les 5 minutes.
TikTok, Instagram et Facebook ne proposent pas de détection publique gratuite et fiable : ils restent activables manuellement depuis l’administration jusqu’à l’obtention d’un accès API officiel.
