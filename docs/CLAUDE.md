# CLAUDE.md — Moteur de rapprochement à 3 voies (ERP BTP)

Fichier de contexte pour l'agent Claude Code. **Lis d'abord `CONCEPTION.md`** (spécification complète : besoins F1–F10, modèle des 11 tables, décisions M1–M10, algorithme) et le diagramme `modele_donnees_3way.mermaid`. Ce fichier-ci résume la stack, les règles d'or et l'ordre de construction. En cas de doute, `CONCEPTION.md` fait foi.

## Objectif

Backend d'un moteur de *3-way matching* : un paiement fournisseur n'est autorisé que si le **bon de commande (PO)**, le **bon de livraison (DN)** et la **facture** concordent (quantités, prix, fournisseur). But : prévenir la fraude (livraisons fictives, manipulation de prix, double paiement). Priorité du livrable : **une API testée** vaut mieux qu'une belle UI.

## Stack & conventions

- **PHP 8.3+ / Laravel** (dernière stable via `composer create-project laravel/laravel`).
- **SQLite** en dev et pour les tests ; MySQL/PostgreSQL visés en prod (rester portable).
- **Tests** : PHPUnit ou Pest (défaut du projet). TDD attendu sur le cœur métier.
- **Structure** :
  - `app/Domain/Matching/` — cœur métier **sans dépendance framework** (`ThreeWayMatcher`, value objects, enum `MatchStatus`). Testable en isolation.
  - `app/Models/` — modèles Eloquent.
  - `app/Services/` — orchestration domaine ↔ persistance (le service qui charge l'état, appelle `ThreeWayMatcher`, écrit décisions/allocations dans une transaction).
  - Contrôleurs API **fins** ; validation dans des Form Requests.
- **Config** : tolérances dans `config/matching.php` (`price_tolerance_pct` = 0.01, `qty_tolerance_abs` = 0.0), lues nulle part en dur.

## Règles d'or (invariants — ne jamais enfreindre)

1. **La ligne de PO est la source de vérité.** DN et facture s'y rattachent par FK. **Aucun lien direct DN↔facture** : ils se rejoignent via la ligne de PO. C'est le mécanisme anti-fraude, pas un raccourci de modèle.
2. **Décimaux partout.** Casts Eloquent `decimal:3` (quantités), `decimal:4` (prix unitaires), `decimal:2` (montants). **Jamais de float** pour money/quantité.
3. **`match_decisions` est append-only.** Pas d'`UPDATE`/`DELETE`. Une révision humaine = **nouvelle ligne** (`actor_type=user`) liée par `supersedes_id` à la décision qu'elle remplace.
4. **Autorisation au prix du PO.** `authorized_amount = authorized_qty × prix_PO`, jamais le prix facturé (défense anti manipulation de prix).
5. **Anomalie → `needs_review`, on autorise 0.** Déclencheurs : prix hors tolérance, OU facturé > commandé, OU fournisseur incohérent, OU article facturé absent du PO. Jamais d'accept/reject silencieux.
6. **Consommation des DN en FIFO** par `received_at` (plus ancien d'abord). Chaque autorisation écrit des lignes `match_decision_consumptions`. Invariants vérifiables :
   - Σ `qty_consumed` d'une décision = son `authorized_qty` ;
   - Σ `qty_consumed` sur une ligne de DN ≤ sa `qty_received`.
7. **`qty_reçue` / `qty_rapprochée` sont des agrégations**, jamais des colonnes stockées (Σ des DN ; Σ des autorisations courantes). Le pool ne compte que les décisions **actuellement autorisées** (une décision superseded libère son allocation).
8. **Cœur métier isolé.** `ThreeWayMatcher` ne connaît ni Eloquent ni la base : il reçoit des données simples et renvoie une décision. Il est **testé unitairement** sans booter Laravel.
9. **Idempotence + intégrité.** Rejouer le rapprochement sur le même état donne le même résultat. Le service enveloppe le calcul dans une **transaction** avec `lockForUpdate()` sur la ligne de PO concernée (anti-concurrence / double allocation).
10. **Traçabilité (règle 5).** Chaque décision fige dans `inputs_snapshot` : qté commandée/reçue/déjà rapprochée, qté facturée, prix PO/facturé, **tolérances appliquées**, DN consommés. Une décision passée reste reproductible même si les seuils changent.

## Statuts (`MatchStatus`)

`matched` (rapproché total) · `partially_matched` (portion livrée autorisée, reste en attente) · `pending_receipt` (rien reçu) · `needs_review` (écart > tolérance, revue humaine).

## Ordre de construction

Avance étape par étape, avec des tests à chaque palier. Ne passe pas à la suivante tant que les tests de la précédente ne passent pas.

- **3a — Schéma.** Migrations des 11 tables (voir `CONCEPTION.md` §2.1), factories, et un seeder de démonstration couvrant les cas limites (match complet, livraison partielle, facturation par livraison, prix hors/dans tolérance, sur-facturation, double facturation, fournisseur incohérent, sur-livraison, article hors PO).
  - *Fait quand* : `php artisan migrate:fresh --seed` passe, contraintes et FK en place.
- **3b — Cœur métier.** `ThreeWayMatcher` + value objects + enum, en PHP pur. Suite de tests unitaires couvrant **tous** les cas limites ci-dessus.
  - *Fait quand* : tests unitaires du domaine verts, sans booter Laravel.
- **3c — API REST + service.** Endpoints : créer PO (+ lignes), enregistrer DN, soumettre facture, **déclencher le rapprochement**, **réviser** une décision `needs_review`, consulter l'état d'un PO / d'une facture. Feature tests bout-en-bout.
  - *Fait quand* : le parcours complet est couvert par des tests de fonctionnalités verts.
- **3d — Documentation.** `DECISIONS.md` (~1 page) : modèle de données, gestion des livraisons/factures partielles, écarts/tolérances, une chose à faire différemment avec plus de temps. Lister explicitement ce qui a été **volontairement laissé de côté** (voir périmètre en §4 de `CONCEPTION.md`).
- **3e — UI minimale** *(si le temps le permet)* : lister les factures avec leur statut de rapprochement et une file de revue des écarts. Optionnel.

## Commandes

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite   # DB_CONNECTION=sqlite dans .env
php artisan migrate:fresh --seed
php artisan test                 # ou ./vendor/bin/pest
```

## Attendu final

Dépôt Git propre + `DECISIONS.md`. Commits atomiques et lisibles. Priorité constante : **exactitude des règles métier et couverture de tests** avant le vernis.
