# PASS50 V22.8 — ouverture des LIVE depuis l’accueil

## Anomalie corrigée
Le compteur LIVE pouvait afficher un direct détecté, mais le clic sur le bouton LIVE échouait avant l’ouverture de la fenêtre à cause d’un appel à une fonction JavaScript inexistante (`avatarInner`).

## Correctifs
- remplacement par un rendu d’avatar robuste ;
- ouverture de la fenêtre LIVE même si le profil n’est pas encore synchronisé localement ;
- validation de l’URL avant affichage du bouton ;
- bouton explicite « Regarder sur YouTube » ;
- affichage facultatif du nombre de spectateurs ;
- mise à jour du cache applicatif.
