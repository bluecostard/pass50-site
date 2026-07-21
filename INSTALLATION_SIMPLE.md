# Installer PASS50 sur IONOS

## Ce qu’il faut préparer

- un hébergement Web Linux IONOS avec PHP et MySQL/MariaDB ;
- un nom de domaine relié à cet hébergement ;
- un compte Brevo et une adresse d’expédition validée.

## Installation en 7 étapes

1. Dans IONOS, crée une base **MySQL ou MariaDB** et conserve : serveur, nom de base, utilisateur et mot de passe.
2. Dans Brevo, valide ton adresse ou ton domaine d’expédition et crée une **clé API**.
3. Décompresse `pass50_ionos_v1.zip` sur ton ordinateur.
4. Envoie tout le contenu du dossier dans l’espace Web IONOS avec WebTransfert ou FTP.
5. Ouvre dans Safari ou Chrome : `https://ton-domaine.fr/install.php`.
6. Colle les informations IONOS et Brevo, puis appuie sur **Installer PASS50**.
7. Après le message de réussite, supprime `install.php` depuis IONOS.

## Premier compte

Le premier utilisateur qui s’inscrit et confirme son e-mail devient automatiquement **propriétaire**. Il est le seul à disposer de tous les droits au départ.

## E-mails PASS50

Brevo envoie :

- le bouton **Confirmer mon compte** après l’inscription ;
- le bouton **Changer mon mot de passe** après une demande de récupération.

Les aperçus sont disponibles dans `emails/confirmation-preview.html` et `emails/reset-password-preview.html`.

## Médias

Les photos et couvertures importées depuis l’administration sont stockées dans :

- `uploads/profile/`
- `uploads/event/`

Elles restent « à valider » avant leur publication publique.

## En cas d’erreur

- vérifie que le domaine utilise HTTPS ;
- vérifie les informations MySQL dans IONOS ;
- vérifie que l’adresse d’envoi est validée dans Brevo ;
- vérifie que PHP utilise une version récente ;
- ouvre `https://ton-domaine.fr/api/` : le message `PASS50 API IONOS` doit apparaître.


## Identité visuelle

Le logo PASS50 est intégré dans `assets/` et utilisé dans le site, la confirmation de compte et la récupération du mot de passe.


## Mise à jour d’une base déjà créée

Exécute `migration-pass50-reset.sql` dans phpMyAdmin pour activer la récupération de mot de passe sur une base existante.
