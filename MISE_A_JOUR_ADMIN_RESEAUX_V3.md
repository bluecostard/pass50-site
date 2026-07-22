# PASS50 — Correction Administration / Réseaux sociaux V3

Cette mise à jour conserve la page d’accueil et la structure publique existantes.

## Correction apportée

1. Dans **Administration**, un onglet clairement nommé **Réseaux sociaux** apparaît juste après **Influenceurs**.
2. Dans **Administration → Influenceurs**, chaque ligne possède maintenant le bouton **Ajouter les réseaux**.
3. Pour chaque influenceur, l’administrateur peut renseigner manuellement :
   - le nom du compte ou l’identifiant ;
   - l’URL officielle exacte ;
   - le nombre d’abonnés ;
   - l’affichage ou le masquage sur la fiche publique ;
   - la confirmation du caractère officiel ;
   - la vérification du lien et sa date de dernier contrôle.
4. Les pages d’accueil générales et les liens de recherche des plateformes ne sont pas publiés comme comptes officiels.
5. Les fichiers JavaScript et CSS utilisent une nouvelle version de cache afin que l’ancienne administration ne reste pas affichée.

## Déploiement

Déposer tous les fichiers du pack à la racine du dépôt en conservant le dossier `api`.
Après déploiement, effectuer une actualisation forcée avec **Ctrl/Cmd + Shift + R**.
