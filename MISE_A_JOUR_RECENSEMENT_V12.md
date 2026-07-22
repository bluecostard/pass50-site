# PASS50 V12 — Import réel du recensement

## Cause du blocage à 37 profils
Le fichier `pass50_nouveaux_candidats_85_v2.json` était présent dans GitHub, mais aucun script de PASS50 ne le lisait. Le simple téléchargement d'un fichier JSON dans le dépôt ne modifie pas la base interne de l'application.

## Correctifs
- import automatique des 85 candidats au démarrage ;
- nouvel import après le chargement de la base cloud MySQL ;
- détection des doublons par identifiant, nom, handle et alias ;
- conservation des profils déjà ajoutés manuellement ;
- nouveaux profils non éligibles et sans score avant vérification ;
- compteur dans Administration > Influenceurs : total recensé / éligibles / en vérification ;
- fusion définitive de Louisette Cadic sous le nom Cadic N’Guessan ;
- version de données portée à 12.

## Résultat attendu
La plateforme possédant actuellement 37 profils peut afficher jusqu'à 122 profils recensés après import. Le nombre exact peut être inférieur si certains des 3 profils ajoutés récemment correspondent déjà à des candidats du fichier.

Le classement public reste limité aux profils marqués `eligible: true`. Les nouveaux candidats apparaissent dans l'administration mais ne sont pas classés artificiellement.

Un bouton **Importer le recensement** est également disponible dans Administration > Influenceurs pour relancer manuellement l'import.
