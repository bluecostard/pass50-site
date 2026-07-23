# PASS50 V22.3 — cycle complet du moteur

## Bug corrigé
Le parcours automatique s’arrêtait après les 16 profils prioritaires. Leur priorité SQL permanente empêchait les autres profils d’entrer dans les lots suivants.

## Nouvelle logique
- chaque lot reçoit la liste des profils déjà parcourus ;
- le serveur exclut ces profils du lot suivant ;
- les profils jamais collectés passent avant les profils déjà traités ;
- les 16 prioritaires gardent un avantage seulement lorsqu’ils sont anciens de plus de 6 heures ;
- le message « Tour complet terminé » n’apparaît que lorsque le compteur atteint réellement le total.

Un nouveau lancement doit ainsi parcourir les 127 profils actifs, par lots de 5, sauf arrêt manuel ou erreur serveur explicite.
