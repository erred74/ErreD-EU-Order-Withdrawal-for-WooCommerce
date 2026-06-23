# Build Progress — Recesso parziale per quantità (feature)

> Estende il recesso parziale da granularità "per riga" a "per quantità": per una riga con
> quantità > 1 il cliente sceglie quante unità recedere. Modello concorrenza: **ledger della
> quantità residua** (più richieste aperte possono prendere parte della quantità, somma ≤ totale;
> incremento atomico condizionato; rilascio sul reject/expire).

**Stato:** ✅ completato e verificato
**Ultimo aggiornamento (GMT):** 2026-06-20

Legend: `[ ]` todo · `[x]` done

## 1. Schema + migrazione
- [x] `Schema.php` — `recesso_dig_claims` diventa ledger per riga: `(id, order_id, line_id, claimed_qty, created_at_gmt)`, `UNIQUE(order_id,line_id)` (niente più `request_id`)
- [x] `Migrations.php` — db_version **3**; upgrade: DROP della tabella claims (transitoria) poi dbDelta ricrea
- [x] `uninstall.php` — già droppa claims (ok)

## 2. Repository (ledger atomico)
- [x] `create_declaration` — `requested_items` = mappa `line_id => qty`; claim atomico per ogni riga
- [x] `claim_quantities` — `INSERT IGNORE` riga ledger + `UPDATE ... SET claimed_qty=claimed_qty+q WHERE claimed_qty+q <= total` (atomico); compensazione su fallimento
- [x] `claimed_quantities(order_id): array<int,int>` (line_id => claimed)
- [x] `transition_status` — su release: decrementa il ledger delle quantità della richiesta
- [x] `DuplicateOpenRequestException` riusata (quantità non disponibile)

## 3. Dominio
- [x] `WithdrawalRequest::requested_items` = mappa `line_id => qty` (from_row gestisce legacy list → qty 0 = intera riga)
- [x] `EligibilityLine` — aggiunge `quantity` (totale riga)
- [x] `EligibilityInput` — `claimed_quantities` al posto di `claimed_line_ids`
- [x] `EligibilityResult` — `available_quantities` (line_id => disponibili); `eligible_line_ids` = chiavi
- [x] `EligibilityEngine` — disponibile = qty − reclamato; eleggibile se qualche riga ha disponibile > 0

## 4. Adapter + Service
- [x] `EligibilityAdapter` — `build_lines` imposta quantity; `build_input` passa `claimed_quantities`
- [x] `WithdrawalService` — valida/clampa le quantità richieste su `available_quantities`; passa `requested_items` + `line_totals` al repo

## 5. REST + Frontend
- [x] `WithdrawalsController` — arg `requested_items` come mappa `{line_id: qty}` (validate/sanitize)
- [x] `EligibilityController` — espone `available_quantities`
- [x] `FlowController` — `line_choices` con disponibili; `handle_declare` legge `requested_qty[line_id]`; confirm con quantità
- [x] `declaration.php` — input quantità per riga (0..disponibili, default disponibili)
- [x] `confirm.php` — elenco articoli con quantità

## 6. Ricevuta + Admin
- [x] `ReceiptBuilder` — `resolve_items` usa le quantità recedute; `is_partial`
- [x] `receipt.php` — quantità + parziale/intero
- [x] `AdminWithdrawalsController` — items con quantità
- [x] `assets/admin/app.js` — mostra quantità (+ rebuild)

## 7. Test + i18n + verifica
- [x] Unit engine: disponibilità, reclamato riduce, tutto reclamato → DUPLICATE_OPEN
- [x] Integration repo: incremento atomico, over-claim bloccato, quote parziali coesistono, release ripristina
- [x] Integration REST/flow: quantità subset; clamp ineleggibili
- [x] Integration receipt: quantità recedute mostrate
- [x] e2e: recesso di quantità < totale riga
- [x] i18n nuove stringhe; `.pot`/`it_IT` aggiornati
- [x] PHPCS 0 · PHPStan L8 0 · suite verde · build · Plugin Check dist 0
