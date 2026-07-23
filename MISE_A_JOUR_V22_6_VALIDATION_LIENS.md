# PASS50 V22.6 — validation fiable des liens officiels

## Problème corrigé
Les plateformes sociales bloquent fréquemment les contrôles serveur ou redirigent les robots vers des pages de connexion. PASS50 interprétait parfois ce blocage comme un lien cassé et annulait une confirmation du propriétaire.

## Nouvelle règle
- La structure de l’URL est contrôlée localement.
- Si le propriétaire coche la confirmation, tout lien direct correctement structuré devient **OFFICIEL** immédiatement.
- Un contrôle distant ne peut plus rétrograder un lien confirmé par le propriétaire.
- Un réseau qui bloque le contrôle affiche **À CONFIRMER** seulement tant que le propriétaire n’a pas confirmé.
- Les pages d’accueil, de connexion, de recherche et les contenus isolés restent refusés.

## No Limit
Les quatre comptes fournis ont été intégrés au recensement et à la migration locale : Facebook, Instagram, TikTok et YouTube.
