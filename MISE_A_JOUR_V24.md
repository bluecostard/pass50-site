# PASS50 V24 — sécurité et six nouvelles fiches

## Profils intégrés

### Intégration prioritaire
- African Ryou
- Samuella Kouassi
- Nadiani
- L’Investisseur Africain

### Recensement validé
- Laura Ziehi
- Aya Robert

## Règles appliquées

- déduplication par ID, nom, handle et alias ;
- aucun faux score ;
- profils non classables par défaut ;
- liens publics uniquement lorsqu’un compte direct a été suffisamment identifié ;
- aucun lien public pour Laura Ziehi et Aya Robert à ce stade ;
- réexécution après le chargement MySQL afin d’éviter qu’un état cloud ancien efface l’ajout.

## Sécurité

- redirection HTTP → HTTPS prête ;
- HSTS progressif ;
- protection clickjacking, MIME sniffing et permissions navigateur ;
- CSP d’abord en mode rapport ;
- blocage de `install.php`, migrations et fichiers de configuration.
