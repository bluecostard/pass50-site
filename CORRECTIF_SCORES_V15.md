# PASS50 V15 — écriture réelle des scores

Corrections :

- tous les profils disposant de données suffisantes sont recalculés, même s’ils étaient déjà classés ;
- les actualités dont le lien original est validé sont importées comme activités du moteur ;
- les métriques écrites dans l’actualité alimentent les critères ;
- `profile.scores` est réellement remplacé pour 2H, 24H, 48H, 7J et 15J ;
- l’API de publication retourne `recalculatedProfiles` et `scoresChanged` ;
- l’interface n’affiche plus « classement actualisé » lorsque zéro score a changé.

Après publication, lancer MAJ PASS50. Le compte rendu doit afficher le nombre exact de profils recalculés et de scores modifiés.
