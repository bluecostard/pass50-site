# PASS50 V22 — moteur biographique et vague prioritaire de 16 profils

## Améliorations
- exploration de sources publiques scolaires, universitaires, diplômes publiés, alumni, CV et institutions ;
- aucune déduction de date de naissance à partir de l’âge scolaire : la date doit être explicitement écrite ;
- extraction de métriques publiques (vues, likes, commentaires, partages) sur les contenus accessibles ;
- calcul automatique du Trend Score avec récence, vues, volume, diversité et confiance ;
- promotion automatique au classement uniquement avec au moins un réseau vérifié et des activités récentes vérifiées ;
- vague V22 de 16 profils prioritaires ;
- endpoint `api/priority-refresh.php` et action cron `priority16`.

## Utilisation
1. Synchroniser les profils dans le Data Hub.
2. Cliquer sur **Actualiser les 16 prioritaires**.
3. Pour la nuit, configurer un cron vers `api/data-cron.php?action=priority16&token=...`, puis un cycle normal toutes les 15 minutes.

Le moteur peut faire évoluer le Top 10 uniquement lorsque les preuves publiques récentes justifient un score.
