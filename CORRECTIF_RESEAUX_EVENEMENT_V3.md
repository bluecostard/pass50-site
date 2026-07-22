# PASS50 — Correctif réseaux et évènement V3

## Corrections appliquées

1. Les anciens liens V6 ne remplacent plus les réseaux saisis dans l’administration lors du chargement local ou cloud.
2. Les liens officiels vérifiés sont relus depuis le Data Engine et réinjectés dans la FI sélectionnée.
3. En cas de remplacement, l’ancienne preuve manuelle est supprimée afin que le nouveau lien soit publié sans conflit.
4. Les conflits déjà présents sont réparés en privilégiant la validation manuelle la plus récente.
5. Le lien exact d’un évènement saisi manuellement reste enregistré ; une URL canonique détectée n’écrase plus la saisie.
6. Le titre, le type et l’explication manuels sont conservés lors de l’analyse et de la validation d’une couverture.
7. Les liens Facebook modernes `share/v`, `share/r` et `share/p` sont acceptés.
8. Un changement d’évènement réinitialise l’ancienne couverture pour éviter d’afficher une image liée au précédent contenu.

## Vérifications effectuées

- Syntaxe JavaScript validée avec Node.js.
- Syntaxe PHP validée sur les fichiers modifiés.
- Test de migration non destructive : les trois réseaux et le nouvel évènement du Père Daloa ne sont plus écrasés.
- Test de reconnaissance des URL Facebook vidéo et partage.
