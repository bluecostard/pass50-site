# PASS50 V14 — correction définitive des LIVE

## Captures de classement

Le bouton MAJ PASS50 ne crée pas de fichiers image.

Les captures sont des instantanés enregistrés dans la table MySQL :

```text
p50_ranking_snapshots
```

Champs principaux :

- `profile_id`
- `period_key`
- `rank_position`
- `trend_score`
- `rank_delta`
- `badges`
- `data_confidence`
- `captured_at`

L’API `api/data-history.php` lit ces instantanés pour l’historique d’une FI.

## Bug LIVE corrigé

Le défaut provenait de deux cas :

1. Les anciens LIVE manuels sans `endsAt` et sans `startedAt` étaient conservés indéfiniment.
2. Ils pouvaient être réinjectés depuis `app_state` après leur suppression locale.

La V14 :

- rejette tout LIVE sans URL, profil ou date exploitable ;
- clôture tout LIVE dont `endsAt` est dépassé ;
- clôture les anciens LIVE incomplets après 8 heures ;
- réduit la sécurité serveur des LIVE automatiques de 45 à 25 minutes ;
- applique le nettoyage côté navigateur et côté serveur ;
- retire automatiquement le badge LIVE correspondant.

## Après publication

1. Ouvrir PASS50 dans un onglet privé.
2. Attendre le premier appel du radar LIVE.
3. Recharger une fois.
4. Vérifier que le direct terminé n’est plus visible.
