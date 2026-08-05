# Cahier des charges — PASS50 Pronostics V1

**Produit :** PASS50  
**Fonctionnalité :** Pronostics (rituel + actu éditoriale)  
**Récompense :** points → accès rencontre avec une FI  
**Statut :** spécification produit V1  
**Date :** 2026-08-05  
**Règle absolue :** aucun argent réel (ni dépôt, ni gain cash)

---

## 1. Vision

PASS50 reste l’**arbitre du buzz**. Les Pronostics transforment le classement / LIVE / actualité en un **rituel quotidien** :

1. L’utilisateur joue (1 à N questions ouvertes)
2. PASS50 clôture avec des **données mesurables**
3. L’utilisateur gagne des **points**
4. Les points servent à **débloquer une rencontre** avec une FI favorite

Le gain = **statut + accès réel**, pas de l’argent.

---

## 2. Objectifs V1

| Objectif | Mesure de succès |
|---------|------------------|
| Habitude quotidienne | ≥ 30 % des users actifs jouent ≥ 3 jours / 7 |
| Viralité | ≥ 15 % des grilles clôturées génèrent un partage |
| Engagement FI | ≥ 1 rencontre ouverte / mois avec une FI partenaire |
| Clarté | 0 question non résoluble (toute question = métrique connue) |

---

## 3. Périmètre V1

### Inclus
- Compte utilisateur (déjà PASS50) obligatoire pour jouer
- **Prono actu uniquement** (question éditoriale créée par l’admin)
- Votes **sans limite** sur toutes les questions ouvertes
- Durée de vote admin **2 / 3 / 7 j** + **date de mesure** séparée
- **Cotes** éditoriales (admin) + répartition des votes
- Clôture, scoring points, streak
- Solde points + historique
- **Statut prono dans Mon fil** (durée 12 / 24 / 48 h) + likes (+0,25 pt)
- Partage du prono / résultat
- Seuil **100 000 pts** → rencontre FI au choix (Phase B, orga à figer)
- Admin : créer / publier / clôturer / (plus tard) gérer demandes rencontre

### Exclus V1 (plus tard)
- Rituel fixe quotidien (Top 3 / climber / LIVE)
- Boosts de cote / cash-out / mises variables user
- Argent réel
- Paris entre users (P2P)
- Commentaires texte ou audio sur statut
- Chat privé user ↔ influ

---

## 4. Concepts

### 4.1 Prono
Une question publiée avec :
- titre / contexte actu
- FI concernée (optionnelle mais recommandée)
- 2 à 4 réponses exclusives avec **cote** décimale
- **durée de vote** fixe : **2 / 3 / 7 jours** → `closes_at`
- **date de mesure** séparée (`measure_at`) — quand on résout / paye
- gain = **mise × cote** (cote figée au moment du vote)
- **règle de résolution** (métrique + seuil)
- statut : `draft` → `open` → `locked` → `resolved` → `archived`

### 4.2 Réponse utilisateur
1 seule option par prono et par user. Modifiable tant que le prono est `open`.

### 4.3 Points
Monnaie virtuelle PASS50, non convertible en argent.

### 4.4 Rencontre
Offre émise par PASS50 / FI partenaire :
- FI concernée
- type (visio 15 min, meet & greet, shout-out live, backstage…)
- coût en points
- places disponibles
- période / modalités
- statut : `available` → `requested` → `confirmed` → `done` / `cancelled`

---

## 5. Expérience utilisateur

### 5.1 Écran Pronostics
- Pronos **ouverts** (actu en premier, puis rituel)
- Compte à rebours par prono
- Solde points + streak
- Entrée « Mes résultats » + « Rencontres »

### 5.2 Jouer un prono actu (exemple)
> Mauvais buzz pour **Influ X**  
> En 7 jours, perte d’abonnés TikTok :  
> ○ + de 400 000  
> ○ + de 300 000  
> ○ moins de 250 000  

Actions :
1. Choisir une option
2. Confirmer
3. Voir « Votes clos le … · Résolution le … »

### 5.3 Résultat
- Bonne réponse → points + animation + carte share
- Mauvaise → 0 ou consolation faible + bonne réponse affichée
- Streak mis à jour

### 5.4 Rencontres
Liste des rencontres déblocables :
- photo FI, titre, coût, places restantes
- bouton **Débloquer avec X pts** (si solde ≥ coût)
- sinon **Il te manque Y pts** + CTA « Jouer »

Après déblocage :
- statut `requested`
- message : « PASS50 te recontacte pour organiser la rencontre »
- l’admin valide avec la FI

---

## 6. Expérience admin

### 6.1 Créer un prono actu
Champs :
- Question
- Contexte / actu (lien FI, news)
- Options (2–4)
- FI liée
- Ouverture + durée vote (2/3/7 j) + date de mesure
- Date de **mesure** (ex. J+7)
- Métrique de résolution (voir §7)
- Points si correct / partiel
- Visuel (optionnel)

### 6.2 Clôturer
- Auto si la métrique est dispo
- Sinon bouton **Résoudre manuellement** (owner/admin) avec preuve affichée

### 6.3 Rencontres
- Créer une offre (FI, type, coût, places, conditions)
- Voir les demandes
- Confirmer / annuler / marquer réalisée
- En cas d’annulation PASS50 : **rembourser les points**

---

## 7. Métriques de résolution autorisées (V1)

Une question n’est publiable que si elle mappe une métrique PASS50 :

| Type | Exemple de question | Source |
|------|---------------------|--------|
| `followers_delta` | Perte / gain d’abonnés sur N jours | Captures métriques / Radar |
| `rank_position` | Finit-il Top 10 / Top 3 ? | Classement public PASS50 |
| `rank_delta` | Qui grimpe le plus ? | Classement public |
| `live_appeared` | Passera-t-il en LIVE sous 24h ? | Radar LIVE |
| `score_threshold` | Score 2H ≥ X ? | Scores PASS50 |

**Règle :** si la métrique n’est pas fiable pour une FI, ne pas ouvrir le prono (ou résolution manuelle owner uniquement).

---

## 8. Économie de points (V1)

### Gains / pertes
| Action | Points |
|--------|--------|
| Bonne réponse prono actu | **mise × cote** (retour total ; mise déjà engagée) |
| Mauvaise réponse | **mise perdue** (déjà débitée au vote) |
| Solde départ | **1000** pts |
| Plancher | **100** pts — jamais en dessous ; mise max = solde − 100 |
| Streak 3 jours | +200 bonus |
| Streak 7 jours | +600 bonus |
| Premier prono du jour | +50 |
| Like reçu sur statut prono | +0,25 |

### Coût rencontre V1 (Phase B)
| Offre | Coût |
|-------|------|
| Rencontre FI partenaire (seuil) | **100 000 pts** |

**Anti-abus V1 :**
- Votes illimités sur questions ouvertes (mise débitée à chaque vote)
- Pas de multi-comptes (mêmes garde-fous auth existants)
- Points non transférables entre users
- Rencontre = 1 demande active max par user (Phase B)

---

## 9. Parcours rencontre (détail)

```
User a assez de points
  → Débloque l’offre (débit immédiat)
  → Demande créée (requested)
  → Admin / FI confirme créneau
  → confirmed
  → Rencontre réalisée → done
```

Si FI refuse ou annulation PASS50 :
- points **recrédités**
- user notifié

Si user no-show (règle à définir avec FI) :
- pas de remboursement (affiché clairement à l’achat)

---

## 10. Écrans à livrer

### Public / app
1. Liste Pronostics
2. Détail prono + vote
3. Résultats / historique
4. Solde & streak
5. Catalogue Rencontres
6. Détail / confirmation déblocage
7. Carte share résultat

### Admin
1. Liste pronos
2. Formulaire création prono actu
3. Résolution manuelle
4. Catalogue rencontres
5. File des demandes rencontre

---

## 11. Données (schéma logique)

- `p50_prono_questions` — question, options JSON, métrique, `opens_at` / `closes_at` (vote 2/3/7 j) / `measure_at`, statut
- `p50_prono_votes` — user_id, question_id, option_key, created_at
- `p50_prono_resolutions` — question_id, winning_option, evidence JSON, resolved_at
- `p50_prono_points_ledger` — user_id, delta, reason, ref_id, created_at
- `p50_prono_balances` — user_id, balance, streak, last_play_date
- `p50_meet_offers` — fi_id, title, cost_points, seats, status…
- `p50_meet_requests` — user_id, offer_id, status, points_spent…

*(Noms exacts à figer à l’implémentation.)*

---

## 12. API (esquisse)

| Endpoint | Rôle |
|----------|------|
| `GET prono-feed.php` | Pronos ouverts + solde user |
| `POST prono-vote.php` | Enregistrer un vote |
| `GET prono-results.php` | Résultats user (résolus) |
| `GET prono-statuses-feed.php` | Statuts live (diapo / Mon fil) |
| `POST prono-status-publish.php` | Publier statut 12/24/48 h |
| `POST prono-status-like.php` | Like statut (+0,25 pt auteur) |
| `GET prono-admin-list.php` | Liste admin |
| `POST prono-admin-save.php` | Créer / éditer (durée 2/3/7 + measureAt) |
| `POST prono-admin-resolve.php` | Clôturer et payer |

Auth : user connecté pour jouer ; owner/admin pour admin.

---

## 13. Phases de livraison

### Phase A — Prono actu (priorité)
- Question éditoriale admin + vote + résolution + points + streak
- UI mobile Pronostics

### Phase B — Rencontre Lo Père
- Offre unique 100 000 pts + débit + file admin
- Process confirmation avec Lo Père Daloa

### Phase C — Suite
- Rituel quotidien + autres FI + carte share avancée

---

## 14. Risques & garde-fous

| Risque | Mitigation |
|--------|------------|
| Métrique abonnés opaque / bloquée | Ne publier que si captures fiables ; sinon résolution manuelle owner |
| Promesse rencontre non tenue | Places limitées, FI partenaires signées, remboursement auto |
| Perception « paris » | Copy claire : jeu gratuit, points, pas d’argent |
| Inflation de points | Ajuster gains/coûts après 2 semaines de data |
| Favoritisme FI | Offres transparentes, une FI = règles identiques |

---

## 15. Copy produit (à respecter)

- Dire **Pronostics** / **cotes** / **points** — jamais « argent », « cash », « bookmaker »
- Toujours : **« Sans argent réel »**
- Rencontre : **« Accès organisé par PASS50 avec la FI »** — pas une garantie de disponibilité 24/7

---

## 16. Décisions validées (2026-08-05)

| # | Question | Décision |
|---|----------|----------|
| 1 | Périmètre V1 | **Prono actu uniquement** (pas de rituel Top 3 / climber / LIVE en V1) |
| 2 | Seuil rencontre | **100 000 points** |
| 3 | FI rencontre | **Influenceur au choix** (orga encore floue — Phase B) |
| 4 | Plateforme métriques abonnés | Source PASS50 la plus fiable au moment de la résolution |
| 5 | Âge 18+ pour rencontre | Non obligatoire en V1 |
| 6 | **Cotes** | **Oui** — cotes éditoriales admin ; gain = **mise × cote** (cote figée au vote) |
| 7 | Limite de votes | **Aucune** — l’user peut jouer toutes les questions ouvertes |
| 8 | **Statut prono** | Après vote, option de publier en statut **dans Mon fil** |
| 9 | Durée du statut | Choix user : **12 h / 24 h / 48 h** |
| 10 | Likes sur statut | **+0,25 pt** par like (plafond anti-abus à définir à l’implémentation) |
| 11 | Partage | Oui — partager son prono / résultat |
| 12 | Commentaires | **Reporté** (texte éventuel plus tard ; pas d’audio en V1) |
| 13 | **Voir les pronos des autres** | **Oui** — diapo type Stories, **sans s’abonner**. Même encadré, on enchaîne les statuts publics |
| 14 | **Fenêtre de vote** | Durées fixes admin : **2 j / 3 j / 7 j** (à partir de l’ouverture) |
| 15 | **Date de mesure** | Séparée de la clôture des votes — moment où on résout le prono (ex. maison finie dans 6 mois) |

### Dates d’un prono (validé)

Exemple : « Lo Père Daloa finit sa 2ᵉ maison dans 6 mois »

| Concept | Rôle | Exemple |
|---------|------|---------|
| Ouverture | Début des votes | Aujourd’hui |
| **Durée de vote** | Choix admin **2 / 3 / 7 jours** → calcule `closes_at` | 7 jours |
| **Date de mesure** | Quand on vérifie le résultat / paye les points | +6 mois |
| Statut user | 12 / 24 / 48 h (indépendant) | Après publication |

Règle : `measure_at` ≥ `closes_at`. On ne vote plus une fois le résultat quasi connu.

### Voir les pronos des autres (validé)

- Pas d’abonnement user ↔ user requis
- Sur Pronostics (et éventuellement Mon fil) : **bandeau + diapo** (comme Snap)
- Un seul encadré plein écran / overlay : question, choix de l’auteur, like, suivant / précédent
- Contenu = statuts encore `live` (non expirés)

### Statut dans Mon fil (validé)

1. User vote sur une question PASS50  
2. Option **« Publier en statut »** → durée 12 / 24 / 48 h  
3. Carte visible dans **Mon fil** (question + choix + compte à rebours)  
4. Like / partage depuis le fil  
5. À expiration → hors fil (reste en historique Pronostics)

La page **Pronostics** = jouer (questions, solde, résultats).  
**Mon fil** = socialiser le prono (statut + likes).

### Économie points V1

| Action | Points |
|--------|--------|
| Bonne réponse prono actu | **mise × cote** (retour total ; mise déjà engagée) |
| Mauvaise réponse | **mise perdue** (déjà débitée au vote) |
| Solde départ | **1000** pts |
| Plancher | **100** pts — jamais en dessous ; mise max = solde − 100 |
| Streak 3 jours | **+200** bonus |
| Streak 7 jours | **+600** bonus |
| Premier prono du jour (même si faux) | **+50** |
| Like reçu sur statut prono | **+0,25** |

Ordre de grandeur (hors likes) : ~1 prono / jour, ~50 % de bonnes réponses → ~7 500–9 000 pts / mois → **~100 000 pts en ~3 mois**. À ajuster après data réelle.

### Rencontre (Phase B — encore flou)

- Seuil : **100 000 pts**
- FI : **au choix** parmi partenaires PASS50 (détail orga à figer avec le produit)
- Process cible : débit → demande → confirmation → réalisation / remboursement si annulation PASS50

### Phases

| Phase | Contenu |
|-------|---------|
| **A** | Questions admin + vote + résolution + points + streak + **statut Mon fil** + likes + share |
| **B** | Rencontre 100k pts (FI au choix) + file admin |
| **C** (plus tard) | Rituel quotidien + commentaires + boosts de cote / cash-out |

---

## 17. Critère « V1 terminée »

- Admin peut publier un prono actu en &lt; 2 min
- User vote, reçoit points à la clôture, voit son solde
- User peut débloquer une rencontre si solde suffisant
- Admin voit la demande et peut confirmer / rembourser
- Aucun flux d’argent réel n’existe dans le code ni l’UI
