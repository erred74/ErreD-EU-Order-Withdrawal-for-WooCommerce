# Build Progress — Recesso Digitale 54-bis

> Canonical source of build state. Read this at the start of every session; update it as work
> proceeds (check off boxes, advance **Next action**). Rules live in the local engineering guide (§0.1).

**Current status:** ✅ MVP + ALL POST-MVP COMPLETE, plus **layout redesign (v0.2.0)** and **competitor
feature set (Plan 2, v0.3.0)**. Backend (data/domain/REST/security), server-rendered two-step flow with the
checkbox + thumbnail + quantity product picker, durable medium (email lists products+data; PDF), React admin
(list/filter/search/timeline/receipt + accept/refund/complete/reject actions, stats summary, CSV export),
i18n (it_IT incl. JS, 216 strings 0 untranslated), E2E+axe, CI matrix, WooCommerce Subscriptions adapter, and
wp.org packaging all built and verified. PHPCS 0, PHPStan L8 0, **20 unit + 58 integration green**, no AI
attribution (§21.1), three pillars + anti-enumeration verified.
**Last updated (GMT):** 2026-06-23
**Next action:** wp.org review-feedback remediation (v0.5.1) DONE — see `PROGRESS-wporg-review.md`.
Distribution now ships the `assets/` source + `package.json`/`package-lock.json` (source
accessibility), Dompdf updated to 3.1.5, PDF `<style>` inlined, admin receipt download + product
status save nonce-hardened, Contributors fixed. Re-run e2e in wp-env, then re-upload to wp.org.

**Audit remediation (pre-wp.org submission, 2026-06-22) — all DONE & verified:**
- Security/integrity: REST `POST /withdrawals` now derives `contract_reference` from the order
  (`$order->get_order_number()`), ignoring any client value (route arg made optional/non-authoritative).
- Hardening: `ReceiptDownloadController::is_within_private_dir()` now checks an exact directory
  boundary (`$real === $base || str_starts_with( $real, $base . DIRECTORY_SEPARATOR )`).
- Packaging: `bin/build-dist.sh` installs from `composer.lock` (reproducible) and injects a
  `defined( 'ABSPATH' ) || exit;` guard into the generated `build/**/*.asset.php` files. `composer.json`
  is kept in the zip on purpose (Plugin Check warns when a shipped `/vendor` has no `composer.json`).
- Lint: `npm run lint:js` and `npm run lint:css` now clean (e2e JSDoc/prettier + CSS line-length fixed);
  `package.json` version aligned to 0.5.0; readme 0.4.0 upgrade notice trimmed under 300 chars.
- Regression tests added: REST contract_reference override + receipt path boundary (3 new cases).
- Verified: PHPCS 0, PHPStan L8 0, 20 unit green, **78 integration green** (265 assertions),
  lint:js/lint:css clean, **Plugin Check on the dist build: 0 errors** (1 justified warning:
  `load_plugin_textdomain`, kept deliberately per the engineering guide §13 for non-repo installs).

**Plan 2 (v0.3.0) — competitor feature set, all DONE & verified:**
- A. Legal: per-product/per-category art. 59 status editor + product-page exclusion notice; checkout
  consents (art. 16(m)/14(4)(a)) stored on the order; Annex I.B printable model form + trader settings.
- B. Admin ops: order notes across the lifecycle; customer status emails (accept/refund/complete) + admin
  notification (multi-recipient); admin Accept/Complete actions + stats endpoint + CSV export.
- C. GDPR: personal-data exporter + eraser (anonymise, retain legal record) + privacy-policy snippet;
  uninstall covers all new options.
- D. UX: optional refund IBAN + reason (db schema v4, carried into the receipt); honeypot; footer link;
  advisory/strict deadline mode + grace days; wpml-config.xml.
// Legal wording (Annex I.B text, consent texts, art. 59 mapping) ships as configurable defaults the
// seller adapts; withdrawals are managed manually by the seller, so the legal-review markers were removed. **Design pivot (merchant-decides):** the 14-day window is now **advisory,
not a gate** — the withdrawal function stays available and the merchant accepts/rejects each request; the
default art. 59 policy is **allow** (merchant excludes products as needed). Rejection now requires a reason,
recorded in the audit trail and emailed to the consumer (durable medium). This removes the date-proxy legal
markers (L1/L2/L4) as blocking assumptions. **Per-line partial withdrawal now DONE** (see
`PROGRESS-recesso-parziale.md`): a consumer can withdraw some/all order lines (incl. variations), with
concurrent per-line atomic claims (new `recesso_dig_claims` table, db schema v2); the durable PDF marks
partial vs whole-order. The durable receipt + email are now produced **synchronously on confirm**, robustly
(fixing a reported case where no PDF/email arrived: deferred async generation, a front-end WP_Filesystem
failure, receipt lost on email error, and the legally-required email silenced by the WC toggle — all fixed).
Every planned and optional item is complete (PHPCS 0, PHPStan L8 0, **15 unit + 40 integration + 6 e2e**,
Plugin Check on dist 0 errors).

Integration tests run inside wp-env: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/wc-reso-ordini php ./vendor/bin/phpunit -c phpunit-integration.xml.dist`

Legend: `[ ]` todo · `[x]` done · `[~]` in progress

---

## Step 0 — Progress tracking
- [x] `PROGRESS.md` created with full checklist
- [x] engineering guide §0.1 directive to read/update this file each session

## Step 1 — M0: Scaffolding, tooling, HPOS bootstrap
- [x] `recesso-digitale.php` (header, guards, HPOS `declare_compatibility`, single `Plugin::boot()`)
- [x] `uninstall.php` (gated by `recesso_dig_delete_data_on_uninstall`)
- [x] `composer.json` (PSR-4 `Recesso54bis\`, dompdf, PHPCS/PHPStan/PHPUnit dev deps, scripts)
- [x] `package.json` (`@wordpress/scripts`, `@wordpress/env`, scripts)
- [x] `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`
- [x] `README.md`, `readme.txt` scaffold
- [x] `src/Plugin.php` (bootstrap, WC-active guard + admin notice, lazy hook providers)
- [x] `src/Support/`: `Clock` (interface + `SystemClock`/`FrozenClock`), `Capabilities`, `Nonces`, `Logger`, `Hashing`
- [x] Verify: `composer install` + `npm install` succeed
- [x] Verify: `composer lint` (PHPCS 0 errors) + `composer analyze` (PHPStan L8 0 errors) clean
- [~] Verify: `npm run build` — deferred to M4/M5 (no JS entry points yet)
- [x] Verify: `npx wp-env start` boots WP+WC, plugin active, no fatal; HPOS declared
- [x] Plugin Check (severity=error): code/header/readme clean; remaining findings are dev files in `.distignore`

## Step 2 — M1: Data layer (custom tables, migrations, repositories)
- [x] `src/Activation/Activator.php` (caps, migrations, options, db_version, HMAC secret)
- [x] `src/Activation/Deactivator.php` (unschedule jobs; no destructive ops)
- [x] `src/Activation/Migrations.php` (idempotent `dbDelta`, `maybe_upgrade` on admin_init)
- [x] `src/Persistence/Schema.php` (both tables, indexes, UNIQUE dup-claim guard via `active_claim`)
- [x] `src/Persistence/RequestRepository.php` (sole SQL surface; write-once `confirm`/`attach_receipt`)
- [x] `src/Persistence/LogRepository.php` (append-only event log)
- [x] `src/Domain/WithdrawalRequest.php` (immutable VO) + `src/Domain/RequestStatus.php`
- [x] `tests/integration/Persistence/RequestRepositoryTest.php` (5 tests) + integration harness
- [x] Verify: tables created w/ indexes/UNIQUE (confirmed in DB); re-activation idempotent; repo tests green

## Step 3 — M2: Eligibility engine (WP-free domain core)
- [x] `src/Domain/Eligibility/EligibilityEngine.php` (pure `evaluate`, fail-closed precedence)
- [x] `src/Domain/Eligibility/EligibilityInput.php`, `EligibilityResult.php`, `Reason.php`, `EligibilityLine.php`
- [x] `src/Integration/EligibilityAdapter.php` (WC bridge, filters, per-request cache) + `src/Support/Settings.php`
- [x] `tests/unit/Domain/Eligibility/EligibilityEngineTest.php` (11 tests)
- [x] art. 59 mapping documented in the adapter (delivery/conclusion window proxies). Legal-review
      markers removed: withdrawals are managed manually by the seller (see audit-remediation note).
- [x] Verify: unit suite green (no WP bootstrap); adapter returns result live; filter override works

## Step 4 — M3: REST API + security
- [x] `src/Rest/RouteRegistrar.php` (namespace `recesso-digitale/v1`) + `src/Container.php` (DI)
- [x] `src/Rest/WithdrawalsController.php` (create / confirm / get; full args schema) + `Controller` base
- [x] `src/Rest/EligibilityController.php` (`GET /eligibility/{order}`, translated reasons)
- [x] `src/Support/OrderToken.php` (HMAC issue, constant-time verify, secret in DB)
- [x] `src/Support/RateLimiter.php` (per-IP throttle, lockout, uniform errors) + `src/Support/ClientIp.php`
- [x] `src/Rest/PermissionGate.php` + `src/Integration/WithdrawalService.php` (+ `NotEligibleException`)
- [x] `tests/integration/Rest/WithdrawalsControllerTest.php` (9 tests, negatives emphasised)
- [x] Verify: token-guest happy path; all negatives 403; duplicate 409; idempotent confirm; no `__return_true`

## Step 5 — M4: Server-rendered two-step flow + block
- [x] `assets/frontend/block.json` + `src/Frontend/Block.php` (dynamic block, server render, built to build/frontend)
- [x] `src/Frontend/FlowController.php` (declaration + «conferma recesso» + done; renders + admin-post handlers)
- [x] admin-post handlers (`recesso_dig_declare`/`recesso_dig_confirm`; nonce + cap/token; sanitise→validate)
- [x] `src/Frontend/Hooks.php` (My Account orders actions + single order view; «recedere dal contratto qui»)
- [x] `src/Frontend/Shortcode.php` (`[recesso_digitale]`) + `FlowUrls`, `FlowPage`, `Templates`
- [x] `templates/frontend/{button,declaration,confirm,done,message}.php` (overridable, escaped)
- [x] Verify: full guest flow over HTTP with JS disabled → confirmed in DB; block registered; `npm run build` OK

## Step 6 — M6: Durable medium (PDF + WC_Email + async)
- [x] `src/Pdf/ReceiptBuilder.php` (canonical payload, Dompdf, SHA-256, protected .htaccess storage)
- [x] `src/Pdf/ReceiptDownloadController.php` (tokenised + cap/owner-checked, traversal-guarded)
- [x] `src/Email/WithdrawalAcknowledgementEmail.php` (`WC_Email` subclass, PDF attachment)
- [x] `src/Email/EmailHooks.php` (`woocommerce_email_classes`)
- [x] `src/Integration/ReceiptScheduler.php` (Action Scheduler async; write-once `receipt_hash`/`acknowledged_at_gmt`)
- [~] `src/Integration/OrderStatus.php` (order note/refund flow) — deferred to post-MVP (records/triggers exist via log + status)
- [x] `templates/pdf/receipt.php`, `templates/emails/withdrawal-acknowledgement.php` (+ plain)
- [x] `tests/integration/Email/ReceiptTest.php` (2 tests)
- [x] Verify: async PDF + email; `receipt_hash` (64) set; `acknowledged_at_gmt` write-once; idempotent; protected dir (live)

## Step 7 — Minimal admin + i18n + Definition of Done
- [x] `src/Admin/Menu.php` + `src/Admin/RequestsListTable.php` (read-only, server-paginated list)
- [x] `src/Admin/SettingsPage.php` (`register_setting` group `recesso_dig`, `show_in_rest`, Settings API screen)
- [x] `languages/recesso-digitale.pot` (77 strings) + complete `it_IT.po`/`.mo` («conferma recesso» verified)
- [~] `wp_set_script_translations` — block editor script only; consumer flow is server-rendered (no JS strings)
- [x] §19 Definition of Done — see verification section below (all green)
- [x] `uninstall.php` drops tables/options/flow-page when opted in (fail-closed)

## End-to-end verification (§19/§20)
- [x] `composer run lint` (PHPCS) → 0 errors
- [x] `composer run analyze` (PHPStan L8) → 0 errors
- [x] `composer run test` (14 unit) + `test:integration` (19 integration) green (write-once, dup-guard, REST negatives)
- [x] wp-env HTTP smoke: guest two-step flow with JS disabled → status=confirmed; durable PDF in protected dir
- [x] Token-guest path; wrong/expired token + unknown id → uniform 403; duplicate → 409; rate-limited
- [x] `wp plugin check` on the **distribution build** (no-dev vendor) → 0 errors, 0 warnings
- [x] Diff grep: 4 routes/4 permission_callbacks; admin-post nonce-checked; no raw SQL w/o prepare; no AI attribution (§21.1)
- [x] HPOS declared + compatible; all order access via wc_get_order/CRUD
- [x] art. 59 window/exclusion mapping documented in EligibilityAdapter (legal-review markers
      removed — manual seller-managed withdrawals)

Reproducible dist build (for Plugin Check / wp.org): rsync excluding `.distignore` patterns (incl. local
dev config, `tests`, `*.dist`), then `composer install --no-dev --optimize-autoloader`; ship `build/` + prod `vendor/` + `composer.json`.

---

## Deferred phase (post-MVP)
- [x] **M5: React admin UI + wp.data store** — `src/Rest/AdminWithdrawalsController.php` (list/detail+timeline/
      process: reject|refunded|regenerate) + `assets/admin/{store,app,index}.js` built to `build/admin`,
      mounted over the server-rendered list (progressive enhancement), enqueued on the admin page.
      Verified: 4 admin REST integration tests; admin page returns 200 with mount node + bundle + nonce. (23 integration total)
- [x] **i18n follow-up**: `.pot` regenerated from PHP+JS (`#:` refs); `it_IT.po`/`.mo` merged + fully translated
      (106 msgs, 0 untranslated); `make-json` JSON for the React admin + block editor, renamed to the **built-bundle**
      hashes (`build/admin/index.js` → `757ecca…`, `build/frontend/index.js` → `0cecefd…`); `wp_set_script_translations`
      given the plugin `languages/` path in `Menu.php` + `Block.php`. Reproducible via `bin/build-i18n.sh`.
      Verified live: `load_script_textdomain` under `it_IT` resolves "Visualizza la ricevuta (PDF)" / "Rifiuta".
- [x] **E2E (Playwright + axe)**: `tests/e2e/recesso.spec.js` (+ `playwright.config.js` extending the wp-scripts base).
      3 specs, all green: guest two-step flow (declaration → «conferma recesso» → acknowledgement, asserts
      status=confirmed + dies a quo in DB), tampered-token refusal (no order leak), accessible React admin list.
      WCAG 2.2 AA axe scans scoped to plugin markup at each step. Orders seeded via wp-cli (`wp eval`, no test-only
      plugin code). `npm run test:e2e`. NB: Playwright 1.61 + Node 22.15 crashes on relative requires from specs →
      helpers inlined (documented in the spec). e2e artifacts/config excluded from dist + git.
- [x] **JS-enhanced no-reload confirm** — `assets/frontend/view.js` (block `viewScript`, deps wp-dom-ready/wp-a11y),
      enqueued for block + shortcode via `FlowController`. Progressive PJAX: intercepts each step's submit, POSTs to
      the same nonce-guarded admin-post handler, follows the redirect and swaps only the `.wp-block-recesso-digitale-flow`
      fragment — no reload — with focus moved to the new heading + `wp.a11y.speak`. Native HTML5 `required`/`type=email`
      handles field validation (no JS duplication); any failure falls back to a real submit; back/forward reloads.
      2 e2e specs (no-reload survives a window marker; native validation keeps the user on step 1). Gotcha fixed:
      `form.action` is shadowed by `<input name="action">` → use `getAttribute('action')`.
- [x] **WooCommerce Subscriptions adapter** — `src/Integration/SubscriptionsAdapter.php`: feature-detected
      (`WC_Subscriptions` + `wcs_get_subscriptions_for_order`), inert when absent (no hard dependency, §16). On
      `recesso_dig_request_confirmed` it **cancels** (status transition, never deletes) each parent/renewal
      subscription that can be updated, and logs every outcome (cancelled / already_inactive / cancel_not_allowed)
      to the audit trail. Wired via Container + `Plugin::register`. PHPStan stubs in `tests/stubs/`. 3 integration
      tests (inert-when-absent + cancel-and-log + skip-already-cancelled) via an injected lookup seam. The
      art. 54-bis/59 mapping is documented in the adapter (legal-review marker removed — manual seller-managed withdrawals).
- [x] **Admin menu count badge** — `Menu.php` adds an "awaiting moderation"-style bubble (count of `confirmed`
      requests awaiting action) to the WooCommerce → Recesso digitale submenu, cached in a 5-min transient and
      invalidated on `recesso_dig_request_confirmed` / `recesso_dig_request_processed` (fired from the admin
      process endpoint). Verified live: `<span class="awaiting-mod count-N">` renders. (WooCommerce Analytics
      nav registration deferred; the menu bubble is the accessible, dependency-free equivalent.)
- [x] **CI workflow** — `.github/workflows/ci.yml`, 3 jobs: **quality** (matrix PHP 8.2/8.3/8.4/8.5 →
      `composer lint` + `analyze` + unit `test`), **integration-e2e** (wp-env: build → integration PHPUnit →
      Playwright/axe e2e, uploads artifacts on failure), **plugin-check** (builds the dist via `bin/build-dist.sh`,
      runs the official `wordpress/plugin-check-action` on the unpacked package). Mirrors the locally-verified commands.
- [x] **Admin free-text search** — `RequestRepository` admin query gains a `search` filter (consumer name,
      contract ref, confirmation email, order id) built from `literal-string` match arms + `esc_like`'d bound
      values (PHPStan L8 + PHPCS clean, no injection). Threaded through the admin REST route, the React app
      (debounced `SearchControl`) and the no-JS `WP_List_Table` (`search_box`). Tested in `RequestRepositoryTest`
      (matches name/email/order id, no-match, wildcard-escaped).
- [x] **wp.org packaging** — `readme.txt` finalised (full description, FAQ, Screenshots, Changelog, Upgrade
      Notice); 4 real screenshots generated into `.wordpress-org/` via a tagged Playwright spec
      (`npm run screenshots`, excluded from the normal e2e run); `.wordpress-org` excluded from the plugin zip.
      **Plugin Check on the distribution build: 0 errors** (one justified `load_plugin_textdomain` warning per §13).
- [x] **Per-line partial withdrawal** (`PROGRESS-recesso-parziale.md`) — a consumer can withdraw some or all
      order lines (incl. variations). New atomic `recesso_dig_claims` table (`UNIQUE(order_id,line_id)`, db
      schema **v2**) lets concurrent partial requests coexist on disjoint lines while blocking a second request
      on a line already being withdrawn; claims are released on reject/expire. Eligibility now excludes
      already-claimed lines instead of blocking the whole order (`claimed_line_ids` replaces `has_open_request`).
      The declaration form gained an accessible, pre-checked item-selection fieldset; the service intersects the
      selection with the eligible lines (fail-closed); REST `POST /withdrawals` gained a validated
      `requested_items` arg; the admin detail and the durable PDF mark partial vs whole-order. Auto-PDF-on-confirm
      was already in place and verified. Tests: per-line claim concurrency (disjoint coexist / overlap blocked /
      release frees), engine claimed-line exclusion, REST subset + ineligible-filter, e2e partial selection.
- [x] **Receipt + email now produced synchronously on confirm, robustly** (`ReceiptScheduler`, `ReceiptBuilder`,
      `WithdrawalAcknowledgementEmail`) — fixes the reported case where the consumer confirmed but no PDF/email
      arrived. Four root causes addressed:
      1. **Deferred generation** — was only enqueued via Action Scheduler (`on_confirmed`), which never ran until
         WP-Cron/an admin acted. Now generated **synchronously on confirm**; a failed sync build is logged and
         retried async when available; `generate()` stays idempotent.
      2. **Filesystem failure in the consumer request** — `ReceiptBuilder::filesystem()` now forces the WP_Filesystem
         `direct` method, so a front-end confirm (no FS credentials, non-direct host) still stores the durable PDF
         instead of throwing (previously surfaced as `sync_build_failed`, no receipt).
      3. **Receipt lost on email failure** — `generate()` now **commits the receipt (write-once) before** attempting
         delivery, and isolates the email in `deliver()` (try/catch) so a mail error never discards the legal record.
      4. **Legally-required email silenced by the WC toggle** — the acknowledgement now sends whenever there is a
         recipient, not gated by `is_enabled()` (a custom WC_Email with no saved settings reports false).
      5. **WC mailer not initialised in the consumer request** (the reported "PDF generato ma email non inviata") —
         the acknowledgement is sent from the front-end confirmation, where `WC()->mailer()` had not run, so the
         `WC_Email` send threw (caught → `email_error`, email unsent) while the admin "regenerate" path worked
         (mailer already up). `deliver()` now calls `WC()->mailer()` first, mirroring the rejection email.
      Also fixed a dev-toolchain break: `doctrine/instantiator` 2.1.0 (PHP ^8.4) pinned down via `config.platform.php`
      = 8.2 → 2.0.0, so the suite runs on the declared PHP floor. Regression tests:
      `test_confirmation_generates_receipt_immediately`, `test_receipt_is_stored_even_when_filesystem_method_is_not_direct`,
      `test_acknowledgement_email_is_sent_even_when_the_email_toggle_is_off`; e2e asserts confirm → `acknowledged` +
      `receipt_hash`, and `test_confirmation_attempts_email_delivery_to_the_consumer` captures the wp_mail attempt.
      Integration renders real PDFs in-process, so `phpunit-integration.xml.dist` raises `memory_limit` to 512M
      (15 unit + 41 integration + 6 e2e green; Plugin Check on dist 0 errors).
- [x] **Partial withdrawal by quantity** (`PROGRESS-recesso-quantita.md`) — for a line with quantity > 1 the
      consumer chooses how many units to withdraw. The claims table became a **per-line quantity ledger**
      (`claimed_qty`, db schema **v3**; `request_id` dropped): a request reserves units via an atomic conditional
      increment (`claimed_qty + n <= line total`), so concurrent requests share a line's units (2 of 4 now, 2 of 4
      later) and over-claims are impossible; rejecting a request returns exactly its units. `requested_items` is now
      a `line_id => quantity` map throughout (VO `from_row` still reads the legacy list shape). Eligibility reports
      `available_quantities` (total − reserved); the declaration form has a per-line quantity input (0..available);
      the service clamps the selection to availability (fail closed); REST `requested_items` is an object map; the
      receipt/admin show withdrawn quantities and a quantity-aware partial flag. Migration self-heals: the claims
      table is dropped+recreated whenever the legacy `request_id` column is present, so activation/upgrade/tests
      converge to the ledger without wiping live reservations afterwards. Also pinned `doctrine/instantiator` to a
      PHP-8.2-compatible release via `config.platform.php` (the toolchain had pulled a PHP-8.4-only version).
      Tests: ledger concurrency (shared-line coexist / over-claim blocked / per-request release), engine available
      quantities, REST quantity reservations, e2e "withdraw 2 of 4". **16 unit + 43 integration + 6 e2e green;
      PHPCS 0; PHPStan L8 0; Plugin Check on dist 0 errors.**

## Installable build
`bin/build-dist.sh` → `dist/recesso-digitale.zip` (validated: installs in WP, 0 Plugin-Check errors/warnings,
includes `build/frontend` + `build/admin` + prod `vendor/` + `it_IT` `.mo`; excludes local dev config/tests/dev config).
