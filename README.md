# Moteur de rapprochement à 3 voies (*3-way matching*)

Contrôle financier d'un ERP BTP : un règlement fournisseur n'est autorisé que
pour la portion d'une facture **prouvée à la fois** par le bon de commande (PO),
le bon de livraison (DN) et la facture — mêmes quantités, même prix, même
fournisseur. Le système n'autorise jamais « sur confiance » : il calcule la
part concordante, signale les écarts pour revue humaine, et trace chaque
décision de façon inaltérable.

Objectif métier : prévenir la fraude (livraisons fictives, manipulation de prix,
paiements en double).

- Spécification : [`docs/CONCEPTION.md`](docs/CONCEPTION.md) (besoins F1–F10, modèle 11 tables)
- Choix d'implémentation : [`DECISIONS.md`](DECISIONS.md)
- Modèle de données : [`docs/modele_donnees_3way.mermaid`](docs/modele_donnees_3way.mermaid)

## Principes clés

| Principe | Mise en œuvre |
|---|---|
| La **ligne de PO** est le pivot unique | DN et factures s'y rattachent par FK ; aucun lien direct DN ↔ facture. |
| Journal **append-only** | `match_decisions` : jamais d'`UPDATE`/`DELETE`, chaque révision crée une ligne liée par `supersedes_id`. |
| **Précision décimale** | `brick/math\BigDecimal` dans le cœur métier — jamais de `float`. |
| **Idempotence** | Rejouer un rapprochement sur le même état redonne le même résultat. |
| **Cœur métier isolé** | `app/Domain/Matching/` ne connaît ni Eloquent ni la base ; testable sans booter Laravel. |
| **Anomalie → revue humaine** | Écart de prix/quantité, fournisseur incohérent, article hors PO → statut `needs_review`, 0 autorisé. |

## Stack technique

- **PHP 8.3+**, **Laravel 13**
- **SQLite** (par défaut, zéro configuration)
- **Laravel Sanctum** — authentification par token + session SPA
- **Inertia.js + Vue 3 + Vite 8** — interface opérateur
- **Tailwind CSS 4**
- **PHPUnit 12** — tests unitaires (cœur métier) et fonctionnels (API + web)

## Prérequis

```sh
php -v        # >= 8.3
composer -V
node -v       # >= 20 recommandé
```

Si PHP ou Composer manque sur macOS :

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

## Installation

```sh
# 1. Dépendances
composer install
npm install

# 2. Environnement
cp .env.example .env
php artisan key:generate

# 3. Base de données SQLite + jeu de démonstration
touch database/database.sqlite
php artisan migrate --seed
```

Le fichier `.env` est déjà configuré pour SQLite (`DB_CONNECTION=sqlite`). Aucune
base externe n'est nécessaire.

Le seed (`DatabaseSeeder`) charge :

- 3 utilisateurs de démo (`UserSeeder`)
- un jeu **déterministe** couvrant les 10 cas limites et les 4 statuts de
  rapprochement (`DemoSeeder`) — état d'entrée uniquement (PO / DN / factures) ;
  les décisions sont produites par le moteur via l'API.

## Lancer le projet

### Tout-en-un (recommandé)

```sh
composer dev
```

Démarre en parallèle : serveur PHP (`http://localhost:8000`), worker de file,
logs (`pail`) et Vite (HMR).

### Ou manuellement, dans deux terminaux

```sh
php artisan serve      # http://localhost:8000
npm run dev            # bundler Vite avec HMR
```

Pour un build de production du front :

```sh
npm run build
```

Ouvrir ensuite **http://localhost:8000** et se connecter avec un compte de démo.

## Comptes de démonstration

Mot de passe commun : **`password`**

| Email | Rôle | Peut trancher les écarts (`is_reviewer`) |
|---|---|---|
| `buyer@demo.test` | Achats | non |
| `clerk@demo.test` | Comptabilité fournisseurs | non |
| `reviewer@demo.test` | Contrôleur / réviseur | **oui** |

## Tests

```sh
composer test
# ou
php artisan test
```

Les tests tournent sur une base SQLite en mémoire (voir `phpunit.xml`) :

- `tests/Unit/Domain` — cœur métier `ThreeWayMatcher` (sans Laravel)
- `tests/Feature/Api` — endpoints REST, orchestration, journal de décisions
- `tests/Feature/Web` — pages Inertia et authentification

## API

Toutes les routes métier sont sous le préfixe `/api` et derrière
`auth:sanctum`. La révision d'un écart exige en plus la capacité
`review-decisions`.

| Méthode & route | Rôle |
|---|---|
| `POST /api/auth/login` | Obtenir un token (throttle 6/min) |
| `POST /api/auth/logout` · `GET /api/auth/me` | Session courante |
| `GET /api/suppliers` · `GET /api/projects` | Référentiel |
| `GET/POST /api/purchase-orders` · `GET /api/purchase-orders/{id}` | Bons de commande |
| `POST /api/purchase-orders/{id}/delivery-notes` | Enregistrer un bon de livraison |
| `GET /api/invoices` · `POST /api/purchase-orders/{id}/invoices` · `GET /api/invoices/{id}` | Factures |
| `POST /api/invoices/{id}/match` | **Lancer / rejouer le rapprochement** |
| `GET /api/match-decisions` | File de revue (décisions `needs_review` système) |
| `GET /api/invoice-lines/{id}/decisions` | Historique des décisions d'une ligne |
| `POST /api/match-decisions/{id}/review` | Trancher un écart (`approve` / `reject`) — réviseur uniquement |

### Postman

Collection et environnement prêts à l'emploi dans [`docs/`](docs/) :

- `docs/postman_collection.json`
- `docs/postman_environment.json` (`baseUrl = http://127.0.0.1:8000`)

Exécuter d'abord la requête *login* : le token est stocké automatiquement dans
la variable d'environnement.

## Structure du projet

```
app/
  Domain/Matching/      Cœur métier pur (ThreeWayMatcher, value objects, enums) — sans Laravel
  Services/             ThreeWayMatchingService — orchestration, transactions, verrous, FIFO
  Http/Controllers/Api/ Endpoints REST
  Http/Controllers/Web/ Authentification des pages Inertia
  Http/Middleware/      RecordActivity (journal d'audit), HandleInertiaRequests
  Models/               Eloquent ; Concerns/AppendOnly protège match_decisions
database/
  migrations/           11 tables métier + Sanctum + activity_logs + contraintes CHECK
  seeders/              UserSeeder, DemoSeeder (jeu déterministe)
resources/js/           Front Inertia + Vue 3 (Pages, Components, Layouts, composables)
config/matching.php     Seuils de tolérance (prix 1 %, quantité 0)
docs/                   CONCEPTION.md, DECISIONS via /DECISIONS.md, collection Postman
tests/                  Unit/Domain, Feature/Api, Feature/Web
```

## Configuration des tolérances

`config/matching.php` — jamais lues « en dur », et **recopiées dans chaque
décision** (`inputs_snapshot`) pour rester reproductible même si les seuils
changent ensuite.

| Clé | Défaut | Sens |
|---|---|---|
| `price_tolerance_pct` | `0.01` | Écart de prix unitaire toléré (1 %). Un écart exactement égal est accepté. |
| `qty_tolerance_abs` | `0.0` | Écart de quantité absolu toléré. |
