# PASS50 — Mise à jour administration et données

- Administration des réseaux plus claire et navigation rapide entre FI.
- Facebook, YouTube et Snapchat disponibles sur toutes les FI.
- Actualité : correction de l’erreur serveur avec GDELT + Google News en secours.
- Validation manuelle du lien original du buzz dans Administration → Actualité.
- Repère TOP 50 dans les listes, sans limiter la base à 50 profils.
- Data Hub compatible avec une base allant jusqu’à 1 000 profils.
- Ajout de Coach Hamond Chic, DBZ, Gorsky et Dolpho.
- Conservation du retour à la même position du Top 50 après fermeture d’une FI.


## Correctif FI Père Daloa — données persistantes

- Les anciennes données V6 ne réécrivent plus les réseaux sociaux ni l’évènement à chaque chargement.
- Le remplacement d’un lien officiel supprime l’ancienne preuve manuelle afin d’éviter les conflits en base.
- Les liens Facebook modernes `/share/v/`, `/share/r/` et `/share/p/` sont reconnus.
- Le lien exact collé par l’administrateur reste la source enregistrée, même si Facebook renvoie une autre URL canonique.
- Le titre, le type et l’explication saisis manuellement ne sont plus remplacés pendant l’analyse ou la validation de la couverture.
- Lorsqu’un évènement change de lien, l’ancienne couverture est réinitialisée pour éviter toute confusion.
