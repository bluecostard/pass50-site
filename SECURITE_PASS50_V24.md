# Sécurité PASS50 — diagnostic V24

## Priorité critique : HTTPS

La mention « Non sécurisé » indique que la visite actuelle n’est pas protégée par une connexion HTTPS valide. Tant que ce point n’est pas corrigé, les mots de passe, jetons de connexion et actions d’administration peuvent être exposés à l’interception ou à la modification sur le réseau.

Ordre correct :

1. Activer/attribuer le certificat SSL à `pass50.store` dans IONOS.
2. Tester directement `https://pass50.store`.
3. Forcer la redirection permanente HTTP → HTTPS.
4. Éliminer les éventuels contenus mixtes.
5. Activer progressivement HSTS.

## Protections déjà observées dans PASS50

- Plusieurs endpoints sensibles imposent une authentification et un rôle `owner` ou `admin`.
- Les collectes d’URL publiques possèdent des contrôles destinés à limiter les requêtes vers des adresses privées.
- Le moteur cron prévoit un jeton secret côté serveur.

Ces contrôles sont utiles, mais ils ne compensent pas l’absence de HTTPS.

## Deuxième priorité : jeton de connexion

La version actuelle enregistre le jeton Bearer dans `localStorage`. Une faille XSS permettrait à un script malveillant de lire ce stockage et de voler une session. Après le passage HTTPS, la prochaine évolution recommandée est :

- session serveur via cookie `Secure`;
- cookie `HttpOnly`;
- `SameSite=Lax` ou `Strict` selon les parcours;
- durée de session courte et rotation du jeton;
- déconnexion/révocation côté serveur.

## Content Security Policy

Le correctif installe d’abord une CSP en mode `Report-Only`, car la page actuelle contient beaucoup de JavaScript et de CSS intégrés. Une CSP stricte appliquée immédiatement casserait l’interface. La phase suivante consistera à déplacer les scripts intégrés dans des fichiers séparés, puis à retirer `unsafe-inline`.

## Fichiers sensibles

Le bloc fourni :

- désactive l’indexation des dossiers;
- bloque les fichiers `.sql`, `.env`, `.ini`, journaux et sauvegardes;
- bloque `install.php` maintenant que l’installation est terminée;
- ajoute des protections contre le clickjacking et le MIME sniffing;
- empêche la mise en cache des réponses PHP sensibles.

## Vérifications après déploiement

- Safari/Chrome affiche un cadenas ou l’indicateur de connexion sécurisée.
- `http://pass50.store` redirige vers `https://pass50.store`.
- l’inscription, la connexion, l’administration et les images fonctionnent;
- aucune erreur « mixed content » dans la console;
- `install.php` et les migrations SQL ne sont plus accessibles publiquement.
