# Déploiement automatique PASS50 vers IONOS

## Résultat

Chaque modification envoyée sur la branche `main` de GitHub est automatiquement publiée dans le dossier IONOS de PASS50.

Le déploiement ne remplace jamais :

- `api/config.php` ;
- le dossier `uploads/` ;
- les photos déjà enregistrées sur IONOS ;
- la base MariaDB/MySQL ;
- `install.php`, qui ne doit plus être remis en ligne.

## Mise en place unique

### 1. Créer un compte SFTP limité à PASS50 dans IONOS

Dans IONOS :

`Hébergement > SFTP & SSH > Créer un compte`

Choisir le dossier :

`/Pass50/pass50_ionos_v2`

Choisir `SFTP` et créer un mot de passe fort.

Conserver :

- le serveur/hôte SFTP ;
- le nom d'utilisateur SFTP ;
- le mot de passe SFTP.

### 2. Créer un dépôt GitHub privé

Nom recommandé : `pass50-site`

Téléverser tous les fichiers de ce dossier dans le dépôt, y compris le dossier caché `.github`.

### 3. Ajouter les quatre secrets GitHub

Dans le dépôt GitHub :

`Settings > Secrets and variables > Actions > New repository secret`

Créer exactement :

- `IONOS_SFTP_HOST` : serveur SFTP affiché par IONOS ;
- `IONOS_SFTP_USER` : utilisateur SFTP ;
- `IONOS_SFTP_PASSWORD` : mot de passe SFTP ;
- `IONOS_REMOTE_DIR` : `/` si le compte SFTP est limité au dossier PASS50, sinon `/Pass50/pass50_ionos_v2`.

### 4. Premier déploiement

Dans GitHub :

`Actions > Déployer PASS50 sur IONOS > Run workflow`

Une coche verte signifie que PASS50 a été mis à jour.

## Pour les prochaines versions

Il suffit de remplacer les fichiers modifiés dans GitHub puis de valider avec `Commit changes`.

Le déploiement se lance automatiquement. Il n'est plus nécessaire de téléverser ni d'extraire un ZIP dans IONOS.

## Sécurité

Ne jamais déposer dans GitHub :

- la clé Brevo ;
- le mot de passe MariaDB ;
- le fichier `api/config.php` provenant du serveur ;
- les identifiants IONOS.
