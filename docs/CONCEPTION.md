# Conception — Moteur de rapprochement à 3 voies (ERP BTP)

> Document de conception consolidé. Clôture de la phase d'analyse (besoins + modèle de données) avant codage.
> Diagramme entité-relation associé : `modele_donnees_3way.mermaid`.

## Contexte

ERP interne d'une entreprise de BTP. Contrôle financier clé : le **rapprochement à 3 voies** (*3-way matching*) pour les règlements fournisseurs. Un paiement n'est autorisé que lorsqu'un **bon de commande (PO)**, un **bon de livraison (DN)** et une **facture** concordent sur les quantités, le prix et le fournisseur. Objectif : **prévenir la fraude** (livraisons fictives, manipulation de prix, paiements en double). Le système n'autorise jamais « sur confiance » — seulement la portion prouvée par les trois voies.

---

## 1. Besoins

### 1.1 Acteurs

| Acteur | Rôle |
|---|---|
| Achats | Crée les bons de commande (PO). |
| Réception / magasin | Enregistre les bons de livraison (DN) au fil des arrivages. |
| Comptabilité fournisseurs | Saisit les factures, consulte le rapprochement. |
| Contrôleur / réviseur | Tranche les écarts signalés. |
| Moteur (système) | Acteur automatique : calcule les rapprochements, écrit les décisions. |
| Trésorerie | Exécute le virement une fois l'autorisation donnée — **hors périmètre**. |

### 1.2 Exigences fonctionnelles

- **F1** — Un PO = 1 fournisseur + 1 projet + n lignes (article, qté commandée, prix unitaire). *(règle 1)*
- **F2** — Plusieurs DN par PO ; livraisons partielles permises ; chaque ligne de DN rattachée à une ligne de PO. *(règle 2)*
- **F3** — Plusieurs factures par PO ; facturation partielle permise ; chaque ligne de facture rattachée à une ligne de PO. *(règle 3)*
- **F4** — Le paiement n'est autorisé que pour la portion d'une facture couverte **à la fois** par les qté/prix du PO **et** par les qté réellement reçues (DN). Rien n'est autorisé sur la portion non rapprochée. *(règle 4)*
- **F5** — Chaque décision (match/mismatch) est traçable : **qui/quoi** l'a produite, **quand**, **sur quelles données**. *(règle 5)*
- **F6** — Tout écart de prix ou de quantité au-delà d'une tolérance faible est **signalé pour revue humaine**, jamais accepté ni rejeté en silence. *(règle 6)*
- **F7** *(dérivée)* — Empêcher le double paiement : une même unité reçue ne peut être autorisée qu'une seule fois, quelle que soit sa répartition sur les factures.
- **F8** *(dérivée)* — Le fournisseur de la facture doit être celui du PO.
- **F9** *(dérivée)* — Ré-évaluation : l'arrivée d'un nouveau DN peut débloquer une portion en attente ; le rapprochement doit pouvoir être recalculé (rejouable).
- **F10** *(dérivée)* — La résolution d'un écart par un réviseur crée une nouvelle décision tracée, sans écraser l'historique.

### 1.3 Exigences non-fonctionnelles

- **Traçabilité inaltérable** — journal de décisions *append-only*.
- **Déterminisme / idempotence** — rejouer un rapprochement sur le même état donne le même résultat.
- **Intégrité transactionnelle** — deux factures rapprochées simultanément ne doivent pas autoriser deux fois le même stock (verrous en base).
- **Précision monétaire** — décimaux partout (money et quantités), jamais de flottants.
- **Cœur métier isolé du framework** — moteur testable unitairement, sans dépendance Laravel.

### 1.4 Cas limites couverts

Match complet · livraison partielle · facturation par livraison · prix hors tolérance · prix dans la tolérance · sur-facturation (facturé > commandé) · double facturation · fournisseur incohérent · sur-livraison (DN > commande) · article facturé absent du PO.

---

## 2. Modèle de données

11 tables, articulées autour de la **ligne de PO**, unique source de vérité (quantité et prix « justes »). DN et facture s'y raccrochent par clé étrangère ; ils ne sont **jamais liés directement** entre eux — ils se rejoignent via la ligne de PO (c'est le mécanisme anti-fraude, cf. M1).

### 2.1 Tables

**Données de référence**

```
suppliers ............. id PK · code UK · name · timestamps
projects .............. id PK · code UK · name · timestamps
```

**Commande (l'ancre)**

```
purchase_orders ....... id PK · reference UK · supplier_id FK→suppliers ·
                        project_id FK→projects · status(draft|open|closed|cancelled=open) ·
                        currency(char3=XOF) · notes? · timestamps
purchase_order_lines .. id PK · purchase_order_id FK · line_no(int) · article_code ·
                        description · unit · qty_ordered(dec14,3) · unit_price(dec14,4) · ts
                        └ UNIQUE(purchase_order_id, line_no) · CHECK qty_ordered>0, unit_price>=0
```

**Livraison**

```
delivery_notes ........ id PK · reference UK · purchase_order_id FK · received_at(date) · notes? · ts
delivery_note_lines ... id PK · delivery_note_id FK · purchase_order_line_id FK · qty_received(dec14,3) · ts
                        └ CHECK qty_received>0 · invariant appli : ligne PO ∈ même PO que le DN
```

**Facturation**

```
invoices .............. id PK · reference · purchase_order_id FK · supplier_id FK ·
                        invoice_date(date) · status(submitted|partially_matched|matched|needs_review=submitted) ·
                        currency · notes? · ts · UNIQUE(supplier_id, reference)
invoice_lines ......... id PK · invoice_id FK · purchase_order_line_id FK? (NULLABLE) ·
                        article_code · description · qty_invoiced(dec14,3) · unit_price(dec14,4) · ts
                        └ CHECK qty_invoiced>0, unit_price>=0
```

**Sorties du moteur**

```
match_decisions ....... id PK · invoice_line_id FK · purchase_order_line_id FK? ·
                        status(MatchStatus) · matchable_qty · authorized_qty · authorized_amount(dec16,2) ·
                        price_delta_pct(dec8,4)? · reasons(json) · actor_type(system|user) · actor_id? ·
                        decided_at(datetime) · inputs_snapshot(json) · supersedes_id FK→match_decisions? · ts
                        └ APPEND-ONLY (jamais d'UPDATE/DELETE) — journal d'audit
match_decision_consumptions
                        id PK · match_decision_id FK · delivery_note_line_id FK · qty_consumed(dec14,3) · ts
                        └ UNIQUE(match_decision_id, delivery_note_line_id) · CHECK qty_consumed>0
                        └ trace relationnelle « décision ↔ livraisons consommées »
payment_authorizations  id PK · invoice_line_id FK · match_decision_id FK ·
                        authorized_qty · authorized_amount(dec16,2) · status(authorized|revoked=authorized) · ts
                        └ UNIQUE(invoice_line_id) sur status=authorized · registre d'allocation courant
```

### 2.2 Cardinalités clés

- `supplier` 1—N `purchase_orders` ; `project` 1—N `purchase_orders`.
- `purchase_order` 1—N `purchase_order_lines`.
- `purchase_order_line` 1—N `delivery_note_lines` (**une ligne de PO livrée en plusieurs fois**).
- `purchase_order_line` 0/1—N `invoice_lines` (facturations partielles ; FK nullable).
- `invoice` 1—N `invoice_lines` ; `supplier` 1—N `invoices` (fournisseur revendiqué).
- `invoice_line` 1—N `match_decisions` (historique) ; `invoice_line` 1—0/1 `payment_authorizations` (courant).
- `match_decision` 1—N `match_decision_consumptions` N—1 `delivery_note_line` (relation N-N porteuse de quantité).

### 2.3 Décisions de modélisation

- **M1 — Ligne de PO = source de vérité unique.** DN et facture s'y raccrochent par FK, jamais par libellé. Aucun lien direct DN↔facture : ils se rejoignent via la ligne de PO. Un lien direct laisserait le fournisseur décider de l'appariement, alors que le contrôle exige que ce soit le moteur (défense anti-fraude).
- **M2 — Asymétrie DN / facture voulue.** Ligne de DN : FK vers la ligne de PO **obligatoire** (réception interne). Ligne de facture : FK **nullable** + article/qté/prix **bruts tels que facturés** (fige la donnée fournisseur pour l'audit ; FK nulle = article hors PO → `needs_review`).
- **M3 — `invoices.supplier_id` = fournisseur revendiqué**, comparé à celui du PO (contrôle F8).
- **M4 — `qty_reçue` et `qty_rapprochée` non stockées** sur la ligne de PO : calculées par agrégation (Σ DN, Σ autorisations courantes) pour éliminer la désynchronisation. Cache possible plus tard.
- **M5 — Deux rôles séparés.** `match_decisions` = historique d'audit *append-only* (règle 5) ; la révision humaine ajoute une ligne liée par `supersedes_id` sans rien écraser (F10). `payment_authorizations` = projection courante de la dernière décision + registre d'allocation anti double-paiement (F7).
- **M6 — Tolérances hors BD** (config applicative, globales) mais **recopiées dans `inputs_snapshot`** à chaque décision → décision passée reproductible même si les seuils changent.
- **M7 — Décimaux partout** (money + quantités). Mono-devise assumée (`currency` informatif ; multi-devises hors périmètre).
- **M8 — FK en RESTRICT** (on ne supprime pas ce qui est audité) ; enums stockés en `string` pour la portabilité SQLite/MySQL/PostgreSQL.
- **M9 — Trace de consommation matérialisée.** Table de liaison `match_decision_consumptions` (décision ↔ ligne de DN + quantité imputée) plutôt qu'une colonne scalaire (impossible : une décision consomme *plusieurs* DN) ou du JSON (non requêtable). Deux invariants contrôlables :
  - Σ `qty_consumed` d'une décision = son `authorized_qty` ;
  - Σ `qty_consumed` sur une ligne de DN ≤ sa `qty_received` (anti double-paiement au grain de la livraison).
- **M10 — Consommation FIFO par `received_at`.** La livraison la plus ancienne est consommée en premier. Comportement déterministe et défendable en audit. Le pool ne compte que les consommations des décisions **actuellement autorisées** : une décision superseded libère son allocation.

---

## 3. Algorithme de rapprochement (rappel)

Rapprochement **par ligne d'article**. Pour chaque ligne de PO, un pool d'allocation partagé empêche de payer deux fois la même marchandise.

```
dispo_commande = qté_commandée − qté_déjà_rapprochée
dispo_reçue    = qté_reçue     − qté_déjà_rapprochée
rapprochable   = min(qté_facturée, dispo_commande, dispo_reçue)
```

Distinction centrale :

- **Anomalie** (prix hors tolérance, OU facturé > commandé, OU fournisseur incohérent, OU article hors PO) → statut `needs_review`, **on n'autorise rien**, revue humaine (règles 4 & 6).
- **Ligne saine mais incomplète** (pas assez livré) → on autorise **la portion livrée** (`partially_matched`) ; le reste attend (`pending_receipt`) — ce n'est pas une anomalie (règle 2).

Le **montant autorisé** est calculé **au prix du PO** (le prix convenu), jamais au prix facturé — défense anti manipulation de prix. Les DN consommés sont imputés en **FIFO** et enregistrés dans `match_decision_consumptions`.

### Statuts (`MatchStatus`)

| Statut | Sens | Autorise |
|---|---|---|
| `matched` | Facturé = rapprochable, prix OK | Oui, en totalité |
| `partially_matched` | Livré < facturé | Oui, la portion livrée |
| `pending_receipt` | Rien reçu encore | Non |
| `needs_review` | Écart > tolérance | Non (revue humaine) |

---

## 4. Périmètre

**Dans le périmètre** : PO / DN / factures, moteur de rapprochement 3 voies, tolérances, autorisation de paiement, traçabilité complète, revue humaine des écarts.

**Hors périmètre** (assumé) : exécution du virement et rapprochement bancaire ; authentification / gestion fine des utilisateurs (un champ `actor` suffit) ; multi-devises ; facture couvrant plusieurs PO ; overrides de tolérance par fournisseur/projet (extension future).

---

## 5. État d'avancement

- **Étape 1 — Besoins** : validée.
- **Étape 2 — Modèle de données** : validée (avec `match_decision_consumptions` + FIFO).
- **Étape 3 — Codage** : à venir. Ordre prévu — (3a) migrations + factories/seeders ; (3b) moteur `ThreeWayMatcher` testé ; (3c) API REST + tests de fonctionnalités ; (3d) `DECISIONS.md` ; (3e) UI minimale si le temps le permet.
