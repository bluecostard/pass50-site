# PASS50 Étape 1 V10.1

Corrections exécutées directement dans le projet complet :

- 6 FI approuvées ajoutées et persistées après le chargement MySQL ;
- FI SmOokii Gamer ajoutée ;
- compteur attendu sur une base de 127 : 134 profils ;
- LIVE sans fin expiré après 8 heures et LIVE sans date après 10 minutes ;
- liens YouTube watch, Facebook watch, Reels, TikTok et X reconnus ;
- FI et Top 5 utilisent la même URL originale validée ;
- sauvegardes Réseaux + Actualité sérialisées pour empêcher l’écrasement aléatoire ;
- cache forcé avec v9-tools.js?v=10.1.

## Contrôle

Après publication et connexion propriétaire, saisir dans la console :

```javascript
PASS50_STEP1_STATUS
```

Résultat attendu : `present: 7`. Le total sera 134 si la base contient bien 127 profils avant migration.

Les collègues de SmOokii Gamer ne sont pas créés sans identité publique vérifiable, afin d’éviter de fausses FI.
