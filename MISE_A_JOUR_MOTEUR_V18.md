# PASS50 V18 — moteur d’enrichissement automatique

## Problème corrigé

Le moteur précédent synchronisait les profils dans la base, mais sa collecte automatique ne parcourait que les profils déjà éligibles au classement. Les nouveaux profils recensés et non classables restaient donc invisibles pour le moteur. Une grande partie des dates de naissance, catégories, biographies, photos et réseaux devait être renseignée manuellement.

## Fonctionnement V18

Le moteur traite désormais tous les profils vivants, qu’ils soient classables ou simplement recensés.

Sources automatiques utilisées :

- Wikidata et Wikipédia, en français et en anglais ;
- données structurées JSON-LD des pages publiques ;
- sites officiels et pages de liens officielles ;
- profils YouTube officiels et flux des vidéos ;
- recherches biographiques publiques via flux RSS ;
- informations déjà enregistrées dans PASS50.

Informations recherchées :

- date de naissance ;
- biographie courte ;
- profession et catégorie suggérée ;
- nationalité ;
- site officiel ;
- comptes sociaux officiels ;
- photo candidate ;
- activité YouTube récente.

## Sécurité des données

- Seules les données atteignant le seuil de confiance configuré, 90 % par défaut, sont publiées.
- Une date issue d’une source structurée fortement associée à la bonne personne peut être publiée automatiquement.
- Les dates trouvées uniquement dans des médias ordinaires doivent être confirmées par plusieurs domaines distincts.
- Les photos trouvées automatiquement restent toujours en attente de validation humaine.
- Une catégorie ou une biographie modifiée manuellement n’est pas écrasée par le moteur.
- Les profils non classables sont enrichis, mais ils ne sont pas ajoutés automatiquement au classement.

## Administration

Dans Administration → Data Hub :

- « Enrichir 5 profils » traite le prochain lot prioritaire ;
- « Enrichir toute la base » lance un tour complet tant que la page reste ouverte ;
- « Arrêter » termine le lot en cours puis interrompt le tour ;
- les profils jamais collectés apparaissent en priorité ;
- les compteurs distinguent naissances vérifiées, dates candidates, réseaux, photos candidates et fiches enrichies.

## Automatisation serveur

L’endpoint `api/data-cron.php` utilise maintenant le même moteur V18 et parcourt toute la base, y compris les profils non classables. Le cron doit être protégé avec `data_engine.cron_token` dans la configuration privée du serveur.
