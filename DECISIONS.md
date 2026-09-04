# DECISIONS.md — Moteur de rapprochement à 3 voies

Ce document explique **les choix d'implémentation** et **pourquoi** ils ont été faits.
Il est écrit pour être compris sans connaître le code : chaque décision technique est
précédée d'une explication en langage courant.

La spécification de référence reste `docs/CONCEPTION.md` (besoins F1–F10, modèle des
11 tables, décisions M1–M10) et `docs/CLAUDE.md` (les 10 « règles d'or »). En cas de
contradiction, c'est `CONCEPTION.md` qui fait foi.

---

## En deux mots

Une entreprise de BTP ne paie un fournisseur que si **trois documents concordent** :

1. le **bon de commande (PO)** — ce qu'on a commandé (article, quantité, prix, fournisseur) ;
2. le **bon de livraison (DN)** — ce qu'on a réellement reçu ;
3. la **facture** — ce que le fournisseur réclame.

Le moteur compare ces trois voies **ligne d'article par ligne d'article** et calcule la
part du montant facturé qui est **prouvée** à la fois par la commande et par la livraison.
Cette part est *autorisée au paiement*. Le reste est soit mis en attente (il manque une
livraison), soit envoyé à un **réviseur humain** (il y a un écart suspect). Le système
n'autorise jamais « sur confiance » — c'est ce qui le rend anti-fraude.

## Glossaire

| Terme | Signification |
|---|---|
| **PO** (*purchase order*) | Bon de commande. 1 fournisseur + 1 projet + n lignes d'article. |
| **DN** (*delivery note*) | Bon de livraison. Ce que le magasin a reçu, saisi au fil des arrivages. |
| **Ligne** | Une ligne d'article (ex. « 100 sacs de ciment »). Tout se rapproche au niveau de la ligne. |
| **Rapprochement** (*matching*) | L'acte de comparer les 3 voies pour une facture. |
| **Autorisation de paiement** | Le montant qu'on accepte de payer pour une ligne de facture, **au prix du PO**. |
| **Décision** (*match decision*) | Le résultat figé d'un rapprochement : statut + quantité autorisée + données d'entrée. Jamais modifié, jamais supprimé. |
| **Anomalie** | Un écart qui dépasse la tolérance (prix, quantité, fournisseur…) → revue humaine. |
| **Tolérance** | Petite marge admise (ex. 1 % sur le prix) en deçà de laquelle on ne signale pas d'écart. |
| **Réviseur** | Personne habilitée à trancher une anomalie (approuver ou rejeter). |
| **FIFO** | *First In, First Out* : on « consomme » les livraisons les plus anciennes d'abord. |
| **Superseded** | Se dit d'une décision remplacée par une plus récente. Elle reste en base pour l'historique. |

## Le cycle de vie, vu de haut

```
Achats crée le PO ─▶ Magasin saisit les DN ─▶ Comptabilité saisit la facture
                                                         │
                                          POST /api/invoices/{id}/match
                                                         ▼
                                   Le moteur (ThreeWayMatcher) évalue chaque ligne
                                                         │
                 ┌───────────────────────┬───────────────┴───────────────┐
                 ▼                       ▼                               ▼
           tout concorde          livré en partie                  écart suspect
          → matched               → partially_matched              → needs_review
          autorise 100 %          autorise la part livrée,         autorise 0,
                                  le reste attend un DN            un réviseur tranche
```

Chaque évaluation écrit **une décision** dans le journal `match_decisions`. Un nouveau DN
ou une révision humaine crée une **nouvelle** décision qui remplace la précédente
(l'ancienne est marquée *superseded*, jamais effacée).

---

## 1. Modèle de données

### 1.1 La ligne de commande est le pivot unique (M1 / règle 1)

**En clair :** le DN et la facture ne se « connaissent » jamais directement. Ils pointent
tous les deux vers la **ligne de PO**, et c'est seulement à travers elle qu'ils se
rejoignent.

**Pourquoi :** si le fournisseur pouvait lier lui-même sa facture à un bon de livraison,
il pourrait fabriquer la correspondance (livraison fictive « justifiant » une facture).
En imposant la ligne de PO comme seul point de jonction, **c'est le moteur — pas le
fournisseur — qui décide de l'appariement**. C'est le cœur du dispositif anti-fraude,
pas un simple choix de modélisation.

### 1.2 DN et facture ne sont pas modélisés de la même façon (M2)

**En clair :** une ligne de DN **doit** être rattachée à une ligne de PO ; une ligne de
facture **peut** ne pas l'être.

**Pourquoi :**
- Le DN est une donnée **interne** (c'est notre magasin qui la saisit) : on peut exiger
  qu'elle soit toujours rattachée à une ligne de commande précise.
- La facture est une donnée **externe** : le fournisseur peut facturer un article qui
  n'était pas dans la commande. On enregistre alors l'article et le prix **bruts, tels que
  facturés** (jamais réécrits), et le lien vers la ligne de PO reste vide. Une FK nulle
  signifie donc explicitement « article hors commande » — c'est une anomalie, pas un bug.

### 1.3 `match_decisions` est un journal « append-only » (M5 / règle 3)

**En clair :** on n'y fait **jamais** de `UPDATE` ni de `DELETE`. Corriger une décision =
en écrire une nouvelle qui référence l'ancienne via `supersedes_id`.

**Pourquoi :** un contrôle anti-fraude doit pouvoir prouver *qui a décidé quoi, quand, et
sur quelles données*, sans que personne ne puisse récrire l'histoire. La chaîne
`supersedes_id` reconstitue tout l'historique d'une ligne de facture.

**Comment :** SQLite ne sait pas interdire `UPDATE`/`DELETE` de façon portable, donc la
garde est **applicative** (`App\Models\Concerns\AppendOnly` lève une exception si on tente
de modifier ou supprimer une décision).

### 1.4 `payment_authorizations` = la photo « en cours » (M5)

**En clair :** c'est le registre qui dit, à l'instant T, quelle quantité et quel montant
sont autorisés pour chaque ligne de facture. Il y a **au plus une** autorisation
`authorized` par ligne de facture.

**Pourquoi :** `match_decisions` est l'historique complet (lourd à interroger) ;
`payment_authorizations` est la projection courante, rapide à lire. Quand une décision
devient *superseded*, son autorisation passe `revoked` et la quantité qu'elle réservait
est **relâchée** dans le pool disponible.

**Comment on garantit l'unicité :** index unique partiel sous SQLite / PostgreSQL ;
verrou applicatif dans le service sous MySQL (qui ne supporte pas cet index).

### 1.5 `match_decision_consumptions` : la trace « décision ↔ livraisons consommées » (M9)

**En clair :** quand une décision autorise 90 unités, cette table dit précisément
**d'où** viennent ces 90 unités : 60 du DN de lundi, 30 du DN de mercredi.

**Pourquoi :** sans cette trace, impossible de vérifier qu'une même unité livrée n'a pas
été payée deux fois. Deux invariants sont vérifiables à tout moment :
- la somme des `qty_consumed` d'une décision = sa quantité autorisée ;
- la somme des `qty_consumed` sur une ligne de DN ≤ la quantité qu'elle a réellement reçue.

### 1.6 Des décimaux partout, jamais de `float` (M7 / règle 2)

**En clair :** l'argent et les quantités ne sont **jamais** manipulés en virgule flottante.

**Pourquoi :** `0,1 + 0,2` ne fait pas exactement `0,3` en flottant. Sur des milliers de
lignes, ces erreurs s'accumulent et faussent des montants — inacceptable pour de la
finance.

**Comment :** casts Eloquent `decimal:3` (quantités), `decimal:4` (prix unitaires),
`decimal:2` (montants) ; le cœur de calcul utilise `brick/math\BigDecimal`. Des contraintes
`CHECK (> 0 / >= 0)` sont posées **au niveau de la base**, déclinées par driver.

### 1.7 Le cœur métier ne connaît pas Laravel (règle 8)

**En clair :** la classe qui contient toute la logique de rapprochement
(`app/Domain/Matching/ThreeWayMatcher`) ne touche ni à la base ni à Eloquent. On lui
donne un objet `MatchInput` (des nombres et des chaînes), elle renvoie un `MatchResult`.

**Pourquoi :** cette logique est la partie critique. L'isoler permet de la **tester en
millisecondes**, exhaustivement, sans démarrer le framework ni une base de données, et de
la faire évoluer sans rien casser autour.

### 1.8 On ne supprime pas ce qui est audité (M8)

Toutes les clés étrangères sont en `RESTRICT` : impossible de supprimer un PO, un DN ou
un fournisseur référencé par une décision. Les enums sont stockés en `string` (et non en
type `enum` SQL) pour rester portable entre SQLite, MySQL et PostgreSQL.

---

## 2. Livraisons et factures partielles

Dans la vraie vie, on est rarement livré et facturé en une seule fois. Le moteur gère ça
**par ligne d'article**, avec un « pool » de quantité partagé sur la ligne de PO.

### 2.1 La formule

```
dispo_commande = quantité commandée      − quantité déjà rapprochée
dispo_reçue    = Σ quantités reçues (DN) − Σ quantités déjà consommées
rapprochable   = min( quantité facturée , dispo_commande , dispo_reçue )
```

Autrement dit : on ne peut autoriser que le plus petit des trois « robinets » — ce qui
est facturé, ce qui reste à commander, et ce qui reste en stock reçu non encore payé.

### 2.2 Un exemple concret

- **PO** : 100 sacs de ciment à **5 000 FCFA** l'unité.
- **Livraisons** : 60 sacs lundi, puis 30 sacs mercredi → **90 reçus**.
- **Facture** : 100 sacs à 5 000 FCFA.
- Rien n'a encore été rapproché.

Calcul :
- `dispo_commande` = 100 − 0 = **100**
- `dispo_reçue` = 90 − 0 = **90**
- `rapprochable` = min(100, 100, 90) = **90**
- **Montant autorisé = 90 × 5 000 FCFA = 450 000 FCFA**
- Statut : **`partially_matched`**. Les 10 sacs restants sont `pending_receipt`
  (on attend une livraison).

Quand le DN des 10 derniers sacs arrive, on relance le rapprochement : `rapprochable`
passe à 100, une **nouvelle décision** autorise 500 000 FCFA, et l'ancienne
(450 000 FCFA) devient *superseded*.

### 2.3 Toujours au prix du PO, jamais au prix facturé (règle 4)

**En clair :** le montant autorisé est `rapprochable × prix du PO`, même si le fournisseur
a facturé plus cher.

**Pourquoi :** si un fournisseur gonfle son prix unitaire, on ne veut surtout pas que ce
prix serve de base au paiement. L'écart de prix est traité séparément (section 3).

### 2.4 Les livraisons sont consommées en FIFO (M10 / règle 6)

**En clair :** on « pioche » d'abord dans les bons de livraison les plus anciens (par
`received_at`). Dans l'exemple ci-dessus : les 60 sacs de lundi, puis 30 des 30 sacs de
mercredi.

**Pourquoi :** c'est le comportement comptable naturel (rotation des stocks) et surtout
c'est **déterministe** : à égalité de date, on départage par `delivery_note_line_id`, donc
deux exécutions donnent exactement la même répartition.

### 2.5 Rejouer un rapprochement donne toujours le même résultat (F9 / règle 9)

**En clair :** appeler `POST /api/invoices/{id}/match` deux fois de suite sur un état
inchangé ne crée **pas** de deuxième décision.

**Pourquoi :** c'est ce qu'on appelle l'idempotence. Sans elle, chaque clic dupliquerait
des décisions et fausserait l'historique.

**Comment :** avant de recalculer une ligne, le service **retire du pool la décision
courante de cette ligne** (elle devient *superseded*, son allocation est relâchée), puis
recalcule à partir de zéro. Si le nouveau résultat est identique à l'ancien, **rien
n'est écrit**. Tout le calcul est enveloppé dans une **transaction** avec `lockForUpdate()`
sur chaque ligne de PO concernée, pour qu'on ne puisse pas autoriser deux fois le même
stock si deux factures sont rapprochées en même temps.

### 2.6 Le statut de la facture est déduit de ses lignes

| Situation des lignes de la facture | Statut de la facture |
|---|---|
| Toutes `matched` | `matched` |
| Au moins une `needs_review` | `needs_review` |
| Au moins une autorisation partielle, aucune anomalie | `partially_matched` |
| Rien de rapprochable pour l'instant | `submitted` |

---

## 3. Écarts et tolérances

### 3.1 Les seuils vivent dans la configuration — et sont figés dans chaque décision (M6 / règle 10)

Les tolérances sont dans `config/matching.php` :

| Clé | Valeur par défaut | Sens |
|---|---|---|
| `price_tolerance_pct` | `0.01` | 1 % d'écart de prix unitaire admis. |
| `qty_tolerance_abs` | `0.0` | aucun écart de quantité admis. |

Elles ne sont **lues nulle part en dur**. Surtout, chaque décision **recopie les
tolérances qu'elle a appliquées** dans son champ `inputs_snapshot`. Conséquence : si on
change le seuil demain, les décisions d'hier restent **reproductibles à l'identique**.

### 3.2 Le test de prix (sans division)

Il y a anomalie de prix si :

```
| prix_facturé − prix_PO |  >  prix_PO × tolérance
```

- On ne divise pas (pas de risque de division par zéro, pas d'arrondi parasite).
- Un écart **exactement** égal à la tolérance est **accepté** (`>`, pas `>=`).
- Le pourcentage d'écart (`price_delta_pct`) est calculé et stocké **dans tous les cas**,
  même quand il n'y a pas d'anomalie, pour l'audit.

### 3.3 Ce qui déclenche une anomalie → `needs_review`, 0 autorisé (règles 4 & 6)

- prix hors tolérance ;
- quantité facturée > (quantité commandée − déjà rapproché) + tolérance de quantité ;
- fournisseur de la facture ≠ fournisseur du PO (F8) ;
- article facturé absent du PO (la fameuse FK nulle).

Dans tous ces cas : statut `needs_review`, **rien n'est autorisé**, jamais d'acceptation
ni de rejet silencieux. On renseigne quand même `matchable_qty` (« on aurait pu rapprocher
X unités, mais on est bloqué par tel écart ») pour aider le réviseur.

### 3.4 Ce qui n'est *pas* une anomalie : la sur-livraison

Si un DN livre plus que commandé, le `min()` de la formule plafonne naturellement au
commandé. L'excédent reçu reste simplement inerte — pas d'alerte.

### 3.5 La révision humaine (F10)

Endpoint : `POST /api/match-decisions/{id}/review`, réservé aux réviseurs
(`Gate review-decisions`).

- **`approve`** → crée une nouvelle décision `actor_type=user` liée à l'ancienne par
  `supersedes_id`. Elle passe par `ThreeWayMatcher::authorizeOverride`, qui **ignore la
  détection d'anomalie** mais **plafonne quand même au rapprochable** et réutilise le même
  calcul décimal + FIFO (rien ne « fuit » dans le service). Le réviseur peut imposer la
  quantité (`authorized_qty`, toujours ≤ rapprochable).
- **`reject`** → nouvelle décision `actor_type=user`, statut `needs_review`, 0 autorisé.
- Seule la décision **courante et `needs_review`** est révisable (sinon `409` ou `422`).
- Une décision prise par un humain est **souveraine** : un futur `matchInvoice` ne la
  recalcule pas. La file de revue ne liste que les décisions `needs_review` produites par
  le **système**.

---

## 4. Authentification & traçabilité — un choix d'aller plus loin

`CONCEPTION.md` §4 place l'authentification **hors périmètre** (« un simple champ `actor`
suffit »). On a **délibérément fait plus**, parce qu'un contrôle anti-fraude perd tout son
sens si l'identité de celui qui décide peut être falsifiée.

### 4.1 Laravel Sanctum plutôt qu'un simple champ texte

Jetons d'API avec expiration 8 h, **révocables immédiatement** (contrairement à un JWT
auto-porteur). Toutes les routes métier sont derrière `auth:sanctum`.

### 4.2 Un vrai rôle réviseur

- `users.is_reviewer` (booléen — un seul rôle pour l'instant) + `Gate review-decisions`
  sur la route de révision.
- Le champ libre `match_decisions.actor_id` de la spec est remplacé par une **vraie clé
  étrangère `actor_user_id` → users** (`RESTRICT`). Impossible d'attribuer une décision à
  un utilisateur qui n'existe pas.

### 4.3 Un journal d'activité distinct de l'audit décisionnel

`activity_logs` + un middleware terminable enregistrent **toute requête mutante**
(`POST/PUT/PATCH/DELETE`) et **toutes les tentatives de connexion** (réussies et échouées) :
méthode, route, acteur, cible, `status_code`, et une **empreinte SHA-256 du corps assaini**
(`password` / `token` caviardés — aucun corps brut n'est stocké). Les `GET` ne sont pas
journalisés.

Ce journal répond à la question « qui a fait quoi sur l'API ». Il est **volontairement
séparé** de `match_decisions`, qui reste l'audit **décisionnel** faisant foi.

---

## 5. Avec plus de temps — pistes d'évolution

Le périmètre livré est volontairement resserré (voir §6). Voici ce qu'on construirait
ensuite, de la fonctionnalité la plus visible pour les utilisateurs à la plus technique.

### 5.1 Notifier les réviseurs (push + e-mail) — priorité

**Le problème aujourd'hui :** quand le moteur détecte une anomalie, la décision atterrit
dans la file de revue… et personne n'est prévenu. Un réviseur doit penser à aller
regarder. Résultat : des factures bloquées, des délais de paiement qui dérapent.

**Ce qu'on ferait :**

- **Déclencheur.** Un évènement métier `MatchDecisionFlaggedForReview` est émis chaque
  fois que le moteur produit une décision `needs_review` (système). Un *listener* mis en
  file (`ShouldQueue`) envoie les notifications — l'API répond sans attendre l'envoi.
- **Canaux, via le système de Notifications de Laravel** (`via()` renvoyant plusieurs
  canaux) :
  - **E-mail** — un `Mailable` Markdown : référence PO, référence facture, ligne
    concernée, type(s) d'écart, `price_delta_pct`, **montant en jeu**, et un **lien
    profond** vers l'écran de revue de cette décision.
  - **Push navigateur** — Web Push (protocole VAPID) pour les réviseurs sur le poste de
    travail ; extensible à FCM / APNs pour une future app mobile. La charge utile push ne
    contient **aucune donnée sensible** (« 1 écart à revoir sur PO-2026-014 ») : le détail
    reste derrière l'authentification.
  - **In-app / temps réel** — canal `broadcast` (Laravel Echo + WebSockets) pour faire
    apparaître un badge « file de revue » qui s'incrémente en direct.
  - **`database`** — chaque notification est aussi persistée (table `notifications`), ce
    qui donne un historique « qui a été prévenu de quoi et quand », cohérent avec la
    philosophie d'audit du projet.
- **Préférences par utilisateur** (`notification_preferences`) : choix des canaux,
  **heures de silence**, et surtout **fréquence de regroupement** — notification immédiate,
  ou **digest** horaire / quotidien récapitulant les écarts en attente. Objectif : éviter
  la fatigue d'alerte qui fait qu'on finit par tout ignorer.
- **Relances et escalade.** Une tâche planifiée balaie les décisions `needs_review`
  ouvertes : au-delà d'un SLA (ex. 24 h), relance du réviseur ; au-delà d'un second seuil,
  escalade au responsable (lien avec 5.4). Chaque relance est elle-même tracée.
- **Notifier aussi les bonnes personnes aux bons moments** : le magasin quand une facture
  est en `pending_receipt` depuis trop longtemps (un DN manque), la comptabilité quand une
  décision passe `matched` (paiement autorisé), le créateur du PO quand un fournisseur
  incohérent est détecté.

### 5.2 Ingestion automatique des factures (OCR / EDI)

Aujourd'hui les factures sont saisies à la main. On ajouterait :

- un **connecteur e-mail / dépôt** qui récupère les PDF de factures fournisseurs ;
- de l'**OCR** (extraction fournisseur, numéro, lignes, quantités, prix) avec un écran de
  validation humaine avant injection ;
- le support **EDI / Factur-X / UBL** pour les fournisseurs capables d'envoyer des
  factures structurées — zéro ressaisie, rapprochement quasi instantané.

### 5.3 Détection d'anomalies assistée

Les règles actuelles sont binaires (dans / hors tolérance). On enrichirait avec :

- un **score de risque** par décision, combinant plusieurs signaux faibles (fournisseur
  récent, prix légèrement au-dessus du marché sur plusieurs lignes, factures juste sous un
  seuil d'approbation, fractionnement suspect de commandes…) ;
- des **alertes de tendance** : « ce fournisseur a augmenté ses prix de 4 % en 3 mois »,
  « taux d'anomalies anormalement élevé sur ce projet » ;
- à terme, un modèle entraîné sur l'historique des décisions humaines pour **pré-trier** la
  file de revue (sans jamais décider à la place de l'humain).

### 5.4 Workflow d'approbation à plusieurs niveaux + SLA

- **Seuils d'approbation par montant** : au-delà de X FCFA, la décision d'un réviseur doit
  être **co-validée** par un second (règle des quatre yeux).
- **Affectation** des écarts à un réviseur (ou round-robin par projet / fournisseur), avec
  file « mes écarts à traiter ».
- **SLA et escalade automatique** (voir 5.1) + tableau de bord des délais de traitement.
- **Délégation** temporaire (congés) avec traçabilité.

### 5.5 Portail fournisseur

Espace en lecture pour le fournisseur : statut de ses factures (rapprochée / en attente
de livraison / en litige), **sans jamais** lui donner la main sur l'appariement. Réduit
les relances téléphoniques tout en gardant le moteur seul maître de la décision.

### 5.6 Intégration ERP / comptabilité / trésorerie

- **Webhooks sortants** (`decision.authorized`, `decision.rejected`, `invoice.matched`)
  pour pousser les autorisations vers le module de paiement.
- Connecteur comptable (écritures d'engagement / de facture) et **rapprochement bancaire**
  en retour, pour boucler le cycle « autorisé → payé → pointé » (aujourd'hui hors
  périmètre, cf. §6).
- **Import des PO** depuis l'ERP achats existant plutôt qu'une double saisie.

### 5.7 Reporting & tableaux de bord

- Vue direction : montants autorisés / en attente / en litige par projet, par fournisseur,
  par période ; délai moyen de traitement des écarts ; top des motifs d'anomalie.
- **Exports d'audit signés** (CSV / PDF horodatés) pour les commissaires aux comptes,
  reconstruisant la chaîne `supersedes_id` d'une facture.

### 5.8 Performance & passage à l'échelle

- **Dénormaliser les agrégats** `qty_received` / `qty_matched` (aujourd'hui recalculés à
  chaque affichage de PO — M4 le prévoit comme extension) : colonnes maintenues par
  évènement, ou `withSum` groupés.
- **Index composites** ciblés — `match_decisions(invoice_line_id, supersedes_id)`,
  couverture de la requête `whereDoesntHave('supersededBy')` — aujourd'hui laissés au
  minimum.
- **Rapprochement asynchrone** : sur gros volumes, déclencher le calcul via un job en file
  plutôt que dans la requête HTTP, avec suivi de progression.
- **Re-matching de masse** : commande / job pour rejouer toutes les factures impactées
  quand un lot de DN arrive, plutôt qu'un appel par facture.

### 5.9 Sécurité & gouvernance renforcées

- **Rôles complets** (`buyer` / `receiver` / `ap_clerk` / `reviewer` / `admin`) et
  politique d'autorisation **par action**, au lieu du seul booléen `is_reviewer`.
- **2FA** obligatoire pour les réviseurs ; expiration de session configurable.
- **`CHECK` conditionnel en base** `actor_type='user' ⇒ actor_user_id NOT NULL`
  (portable), aujourd'hui garanti seulement côté service.
- **Chaînage cryptographique** du journal `activity_logs` (chaque entrée référence le hash
  de la précédente) pour rendre toute suppression détectable.
- **Rétention & purge** encadrées (RGPD) sur les données non auditées.

### 5.10 Confort fonctionnel

- **Pagination** et filtres avancés sur toutes les listes (factures, décisions).
- **Tolérances par fournisseur / par projet** (aujourd'hui globales uniquement).
- **Facture couvrant plusieurs PO** (aujourd'hui : une facture = un seul PO).
- **Multi-devises** réel (aujourd'hui `currency` est informatif, mono-devise assumée).
- **Commentaires / pièces jointes** sur une décision en revue (mail du fournisseur, photo
  du bon de livraison papier…).

---

## 6. Volontairement laissé de côté (périmètre — `CONCEPTION.md` §4)

| Écarté | Raison |
|---|---|
| **Exécution du virement & rapprochement bancaire** | Le système *autorise*, la trésorerie *paie*. Hors périmètre. |
| **Multi-devises** | `currency` est purement informatif ; mono-devise assumée (M7). |
| **Facture couvrant plusieurs PO** | Une facture est rattachée à un seul PO. |
| **Tolérances par fournisseur / projet** | Tolérances globales uniquement. |
| **Gestion fine des utilisateurs** (inscription, mot de passe oublié, rôles multiples) | Utilisateurs créés par seeder / tinker. |
| **Notifications, OCR, workflow multi-niveaux, reporting** | Décrits en §5 comme évolutions ; non implémentés. |

> Note : l'UI opérateur (Inertia + Vue 3) mentionnée comme optionnelle (étape 3e) **a été
> réalisée** — liste des factures avec statut, détail PO / facture, file de revue des
> écarts. Le livrable principal reste néanmoins l'**API testée** (suite de tests verte).
