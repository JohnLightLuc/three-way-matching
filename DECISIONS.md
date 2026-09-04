# DECISIONS.md — Moteur de rapprochement à 3 voies

Synthèse des choix d'implémentation. La spécification fait foi : `docs/CONCEPTION.md`
(besoins F1–F10, modèle 11 tables, décisions M1–M10) et `docs/CLAUDE.md` (règles d'or).

---

## 1. Modèle de données

- **La ligne de PO est le pivot unique** (M1 / règle 1). DN et factures s'y rattachent par
  clé étrangère ; il n'existe **aucun lien direct DN ↔ facture**. C'est le mécanisme
  anti-fraude : c'est le moteur — pas le fournisseur — qui décide de l'appariement.
- **Asymétrie DN / facture voulue** (M2). Ligne de DN : FK vers la ligne de PO
  **obligatoire** (réception interne). Ligne de facture : FK **nullable** + article / prix
  **bruts tels que facturés** (donnée figée pour l'audit ; FK nulle = article hors PO).
- **`match_decisions` est un journal append-only** (M5 / règle 3) — ni `UPDATE` ni
  `DELETE`. Toute nouvelle évaluation ou révision crée une **ligne** liée par
  `supersedes_id`. Garde applicative (`App\Models\Concerns\AppendOnly`), SQLite n'offrant
  pas d'interdiction portable.
- **`payment_authorizations` = projection courante** (M5) : registre d'allocation, une
  seule ligne `authorized` par ligne de facture (index unique partiel sous
  SQLite/PostgreSQL ; verrou de service sous MySQL). Quand une décision est *superseded*,
  sa PA passe `revoked` et son allocation est libérée.
- **`match_decision_consumptions`** matérialise la trace « décision ↔ lignes de DN
  consommées » avec quantité (M9). Deux invariants : Σ `qty_consumed` d'une décision =
  son `authorized_qty` ; Σ `qty_consumed` sur une ligne de DN ≤ sa `qty_received`.
- **Décimaux partout** (règle 2 / M7). Casts `decimal:3` (quantités), `decimal:4` (prix
  unitaires), `decimal:2` (montants). Le cœur métier calcule avec `brick/math\BigDecimal` —
  **jamais de `float`**. Contraintes `CHECK (> 0 / >= 0)` posées au niveau base, par driver.
- **Cœur métier isolé** (règle 8). `app/Domain/Matching/` (`ThreeWayMatcher`, value
  objects, enums) ne connaît ni Eloquent ni la base : il reçoit un `MatchInput`, renvoie
  un `MatchResult`. Testé unitairement sans booter Laravel.
- **FK en `RESTRICT`** (M8) : on ne supprime pas ce qui est audité. Enums stockés en
  `string` pour la portabilité SQLite / MySQL / PostgreSQL.

## 2. Livraisons et factures partielles

Rapprochement **par ligne d'article**, avec un pool d'allocation partagé sur la ligne de PO :

```
dispo_commande = qté_commandée − qté_déjà_rapprochée
dispo_reçue    = Σ qté_reçue    − Σ qté_déjà_consommée
rapprochable   = min(qté_facturée, dispo_commande, dispo_reçue)
```

- **Ligne saine mais incomplète** (pas assez livré) → `partially_matched` : on autorise la
  **portion livrée**, le reste attend (`pending_receipt`). Ce n'est **pas** une anomalie.
- **Montant autorisé = `rapprochable × prix du PO`** (règle 4), jamais le prix facturé —
  défense anti manipulation de prix.
- **Consommation des DN en FIFO** par `received_at` (M10), enregistrée dans
  `match_decision_consumptions`. Départage déterministe par `delivery_note_line_id`.
- **Ré-évaluation rejouable** (F9). L'arrivée d'un nouveau DN peut débloquer une portion
  en attente : on relance `POST /api/invoices/{id}/match`. Le service **exclut la décision
  courante de la ligne évaluée** du pool (elle devient *superseded*, son allocation est
  relâchée), de sorte que rejouer sur le même état redonne le même résultat
  (**idempotence**, règle 9) : si la nouvelle décision est équivalente à la courante,
  **aucune ligne n'est écrite**.
- Le service enveloppe le calcul dans une **transaction** avec `lockForUpdate()` sur
  chaque ligne de PO concernée (anti double-allocation concurrente).
- **Statut de facture** dérivé des décisions courantes de ses lignes : `matched` si toutes
  matched · `needs_review` si au moins une · `partially_matched` s'il y a une autorisation
  partielle · sinon `submitted` (rien de rapprochable encore).

## 3. Écarts et tolérances

- Seuils dans `config/matching.php` (`price_tolerance_pct = 0.01`,
  `qty_tolerance_abs = 0.0`), lus **nulle part en dur**, et **recopiés dans
  `inputs_snapshot`** à chaque décision (M6 / règle 10) : une décision passée reste
  reproductible même si les seuils changent ensuite.
- **Test de prix exact** (pas de division) : anomalie si `|prix_facturé − prix_PO| >
  prix_PO × tolérance`. Un écart **exactement** égal à la tolérance est accepté.
  `price_delta_pct` (en %) est calculé et stocké dans tous les cas, pour l'audit.
- **Anomalie → `needs_review`, on autorise 0** (règles 4 & 6). Déclencheurs : prix hors
  tolérance · facturé > (commandé − déjà rapproché) + tolérance quantité · fournisseur de
  la facture ≠ fournisseur du PO (F8) · article facturé absent du PO (FK nulle). Jamais
  d'accept/reject silencieux. `matchable_qty` reste renseigné même en anomalie (« on
  aurait pu rapprocher X, mais bloqué par Y »).
- **Sur-livraison** (DN > commande) n'est **pas** une anomalie : le `min()` plafonne
  naturellement au commandé, l'excédent reçu reste inerte.
- **Révision humaine** (F10). `POST /api/match-decisions/{id}/review` :
  - `approve` → nouvelle décision `actor_type=user` liée par `supersedes_id`, autorisée
    via `ThreeWayMatcher::authorizeOverride` (ignore la détection d'anomalie, **plafonne
    au rapprochable**, réutilise le calcul décimal + FIFO — rien ne fuit dans le service).
    Quantité imposable par le réviseur (`authorized_qty`, ≤ rapprochable).
  - `reject` → nouvelle décision `actor_type=user`, statut `needs_review`, 0 autorisé.
  - Seule la décision **courante** et `needs_review` est révisable (sinon 409 / 422).
  - Une décision courante prise par un humain est **souveraine** : `matchInvoice` ne la
    ré-évalue pas. La file de revue ne liste que les décisions `needs_review` **`system`**.

## 4. Authentification & traçabilité — au-delà du périmètre

`CONCEPTION.md` §4 pose l'authentification hors périmètre (« un champ `actor` suffit »).
**Choix assumé de faire plus**, un contrôle anti-fraude ayant besoin d'un acteur non
falsifiable :

- **Laravel Sanctum** (token, expiration 8 h) plutôt que JWT pur — révocation immédiate.
  Toutes les routes métier derrière `auth:sanctum`.
- **`users.is_reviewer`** (booléen, un seul rôle pour l'instant) + `Gate review-decisions`
  sur la route de révision. `match_decisions.actor_id` (chaîne libre) remplacé par une
  **vraie FK `actor_user_id` → users** (`RESTRICT`).
- **`activity_logs`** + middleware terminable : journalise toute requête **mutante**
  (`POST/PUT/PATCH/DELETE`) et les logins (succès et échec) — méthode, route, acteur,
  cible, `status_code`, empreinte `sha256` du corps **assaini** (`password` / `token`
  caviardés ; aucun corps brut stocké). Les `GET` ne sont pas journalisés. Ce journal est
  distinct de `match_decisions`, qui reste l'audit **décisionnel** faisant foi.

## 5. Avec plus de temps

- **Cacher les agrégats** `qty_received` / `qty_matched` (aujourd'hui recalculés par
  requête à chaque affichage de PO — M4 le prévoit comme extension) via des colonnes
  dénormalisées maintenues par événement, ou des `withSum` groupés.
- **Index composites** ciblés (`match_decisions(invoice_line_id, supersedes_id)`,
  couverture de `whereDoesntHave('supersededBy')`), aujourd'hui laissés au minimum.
- **Rapprochement asynchrone** (job en file) sur gros volumes, plutôt que synchrone dans
  la requête.
- **`CHECK` conditionnel** `actor_type='user' ⇒ actor_user_id NOT NULL` au niveau base
  (portable), aujourd'hui garanti seulement côté service.
- Rôles complets (`buyer` / `receiver` / `ap_clerk` / `reviewer`) et politique
  d'autorisation par action, au lieu du booléen `is_reviewer`.
- Endpoint d'historique des décisions d'une ligne (`GET .../decisions`) et pagination des
  listes.

## 6. Volontairement laissé de côté (périmètre — CONCEPTION.md §4)

- **Exécution du virement et rapprochement bancaire** — le système autorise, la trésorerie
  paie (hors périmètre).
- **Multi-devises** — `currency` est informatif, mono-devise assumée (M7).
- **Facture couvrant plusieurs PO** — une facture est rattachée à un seul PO.
- **Overrides de tolérance par fournisseur / projet** — tolérances globales uniquement.
- **UI** (étape 3e) — non réalisée ; le livrable est l'API testée (68 tests verts).
- **Gestion fine des utilisateurs** (inscription, mot de passe oublié, rôles multiples) —
  utilisateurs créés par seeder / tinker.
