# Build Progress — Recesso parziale per riga (feature)

> Tracciamento dei progressi di questa feature fino a conclusione totale. Aggiornare i box man
> mano. Regole nella guida di sviluppo locale; stato generale del plugin in `PROGRESS.md`.

**Obiettivo:** consentire il recesso da uno/alcuni prodotti (incl. varianti) di un ordine
multi‑prodotto, con **claim per‑riga concorrenti** (richieste aperte simultanee su righe disgiunte).
Granularità: **per riga** (no quantità). Invio PDF alla conferma: **già esistente** (verificare).

**Stato:** ✅ COMPLETATO — implementazione + verifiche eseguite.
**Ultimo aggiornamento (GMT):** 2026-06-20

Legend: `[ ]` todo · `[x]` done · `[~]` in corso

---

## 1. Tabella claim atomici per riga
- [x] `src/Persistence/Schema.php` — `CLAIMS_TABLE`, `claims_table()`, statement dbDelta con `UNIQUE(order_id,line_id)`
- [x] `src/Activation/Migrations.php` — bump `CURRENT_VERSION` → '2' (crea la tabella su install esistenti)
- [x] `uninstall.php` — drop tabella claims (dietro opt‑in)

## 2. Repository: claim per‑riga
- [x] `create_declaration` — insert request (active_claim sempre NULL) + INSERT multi‑riga atomico in claims; rollback (delete request) + `DuplicateOpenRequestException` su violazione UNIQUE
- [x] `claimed_line_ids(order_id): int[]`
- [x] `transition_status` — su release (rejected/expired) elimina le righe claim del request_id
- [x] Rimosso `find_open_by_order` + `claim_key` (non più usati)

## 3. Eligibilità per‑riga
- [x] `EligibilityInput` — `claimed_line_ids` al posto di `has_open_request`
- [x] `EligibilityEngine` — escludere righe reclamate (no blocco ordine); reason DUPLICATE_OPEN se tutte le righe altrimenti eleggibili sono reclamate
- [x] `EligibilityAdapter` — passa `claimed_line_ids(order_id)`

## 4. Service
- [x] `WithdrawalService::create_declaration` — accetta `requested_items`, interseca con `eligible_line_ids` (fail‑closed), default = tutte le eleggibili; vuoto → `NotEligibleException(NO_ELIGIBLE_ITEMS)`

## 5. Frontend
- [x] `FlowController::render_declaration` — risolve righe eleggibili (label+qty) e le passa al template
- [x] `FlowController::handle_declare` — legge `requested_items[]` (absint), valida non‑vuoto, passa al service
- [x] `FlowController::render_confirm` — passa la lista articoli selezionati
- [x] `templates/frontend/declaration.php` — fieldset checkbox `requested_items[]` pre‑selezionate, accessibile
- [x] `templates/frontend/confirm.php` — elenco articoli oggetto di recesso

## 6. REST
- [x] `WithdrawalsController` — arg `requested_items` (array int, validate/sanitize) → service
- [x] `AdminWithdrawalsController` — dettaglio espone `requested_items` + etichette + `is_partial`

## 7. Admin React
- [x] `assets/admin/app.js` — mostra gli articoli oggetto di recesso nel dettaglio
- [x] `npm run build` → rigenera `build/admin`

## 8. Ricevuta / PDF
- [x] `ReceiptBuilder` — payload `is_partial`
- [x] `templates/pdf/receipt.php` — riga «Tipo di recesso: parziale/totale»

## 9. Test
- [x] Unit `EligibilityEngineTest` — firma `claimed_line_ids`; righe reclamate escluse; tutte reclamate → DUPLICATE_OPEN
- [x] Integration `RequestRepositoryTest` — claim per‑riga: disgiunte coesistono, sovrapposte bloccate, release libera; `claimed_line_ids`
- [x] Integration `WithdrawalsControllerTest` — create con subset; filtro id non eleggibili; richieste disgiunte coesistono
- [x] Integration `FlowControllerTest` — declare con righe selezionate (verificato esistente: copre il flusso)
- [x] E2E `recesso.spec.js` — selezione subset → conferma (helper aggiornati per il fieldset)

## 10. i18n
- [x] Nuove stringhe avvolte; `.pot` rigenerato; `it_IT.po/.mo` aggiornati

## 11. Verifica finale
- [x] `composer lint` (PHPCS 0) + `composer analyze` (PHPStan L8 0)
- [x] unit + integration (wp‑env) verdi
- [x] `npm run build` + e2e
- [x] smoke manuale: parziale 1 riga, seconda richiesta su altra riga consentita, stessa riga bloccata, reject libera
- [x] verificato invio PDF automatico alla conferma (#2)
- [x] `wp plugin check` su dist → 0 errori
- [x] aggiornato `PROGRESS.md`
