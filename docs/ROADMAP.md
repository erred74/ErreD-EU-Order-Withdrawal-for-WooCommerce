# Roadmap

Work not yet done, why it matters, and what it touches. Ordered by release. Rules live in
`claude.local.md`; this file records *intent*, not state — a completed item is deleted from here and
described in the `readme.txt` changelog instead.

Last reviewed: 2026-08-13, after 0.6.0.

---

## Context: where we stand

0.6.0 closed the gap opened by a feature-by-feature comparison against
[`eu-withdrawal-compliance`](https://it.wordpress.org/plugins/eu-withdrawal-compliance/) (AyudaWP),
our closest competitor on wordpress.org. We are ahead on architecture — signed HMAC guest tokens,
append-only custom tables with an audit log, a real PDF durable receipt, partial per-line withdrawal
with an atomic claims ledger, WCAG 2.2 AA, and now Checkout-block consents, which they list as
roadmap. They remain ahead on **admin ergonomics** and on **breadth of installation** (they run
without WooCommerce).

Everything below came out of that comparison unless marked otherwise.

---

## 0.7.0 — admin ergonomics and evidence

The theme: a merchant handling more than a handful of withdrawals a month currently does too much
one request at a time, and the evidence trail stops short of what a dispute needs.

### Bulk actions on the requests list
Accept, reject or complete several requests at once, firing the same customer email as the single-request
path. Today every decision goes through the detail panel.
Touches `src/Admin/RequestsListTable.php`, `src/Rest/AdminWithdrawalsController.php`, `assets/admin/`.
Note: rejection requires a reason, so a bulk rejection needs one reason prompt for the whole batch —
do not silently skip the requirement.

### Filtered CSV export
Export by status **and date range**, from its own screen rather than only the current list filter.
Touches `src/Admin/CsvExporter.php`, `src/Admin/Menu.php`.

### User-Agent capture
We store the client IP as submission evidence but not the User-Agent; the competitor stores both, and
for a disputed submission the pair is worth more than either alone. Needs a nullable column and a
schema bump (v6), following the pattern of the v5 `consumer_declaration` column.
Touches `src/Persistence/Schema.php`, `src/Activation/Migrations.php`, `src/Support/ClientIp.php`
(a sibling helper), `src/Frontend/FlowController.php`.

### Checkout-consent evidence, and surfacing it
Today a consent is stored as `'1'`/`'0'` plus a timestamp. For a dispute the useful record is the
**exact text the customer agreed to**, plus IP and User-Agent — the wording can change after the
order. Snapshot it on the order at checkout, and show the captured consents in the admin request
detail, which currently never mentions them.
Touches `src/Frontend/CheckoutConsents.php`, `src/Frontend/BlockCheckoutConsents.php`,
`src/Rest/AdminWithdrawalsController.php`, `assets/admin/app.js`.

### Order-number plugin compatibility
The public lookup form matches the raw WooCommerce order number. Stores using Sequential Order
Numbers or Custom Order Numbers show the customer a different number, so a legitimate lookup fails
and the consumer gets silence. Add a resolver checking the known meta keys, with
`recesso_dig_order_number_meta_keys` and `recesso_dig_resolve_order` filters for anything else.
Touches the lookup path in `src/Frontend/FlowController.php`.

### WPML: resolve a translated product's withdrawal status to the original
On WPML/Polylang sites a translated product does not inherit the "Withdrawal status" set on the
original, so its exclusion notice and checkout consent silently disappear. The competitor shipped
this as a 2.1.1 fix; we have the same bug.
Touches `src/Integration/EligibilityAdapter.php`, `src/Admin/WithdrawalStatusFields.php`.
Per-translation overrides must still win, for genuinely language-specific exceptions.

### Document the public hooks in `readme.txt`
We expose filters (`recesso_dig_is_eligible`, `recesso_dig_withdrawable_statuses`,
`recesso_dig_entry_token_ttl`) and actions (`recesso_dig_request_created`,
`recesso_dig_request_confirmed`, `recesso_dig_request_processed`,
`recesso_dig_after_declaration_form`, `recesso_dig_generate_receipt`) with no public documentation.
The competitor advertises "11 filters, 4 actions" as a selling point; ours are simply invisible.

---

## 0.8.0 and later — features they have announced but not shipped

Cheap to build, and each one is a line we can claim first.

- **Custom "Withdrawal requested" order status**, with an opt-in automatic transition on
  confirmation. New `src/Integration/OrderStatus.php`. Must be opt-in: it changes the merchant's
  fulfilment workflow.
- **Dashboard widget** with counters, pending requests and a monthly count. We have a menu badge
  only. New `src/Admin/DashboardWidget.php`.
- **Withdrawal-link block and classic widget.** We ship a block for the form but not for the link;
  the link is currently a footer toggle or a shortcode.

---

## Known limitations, deliberate

Not bugs. Recorded so they are not "discovered" again and quietly reversed.

### No unverified withdrawal records
The competitor's headline 2.1.0 feature registers requests that match no order, flagged "Unverified".
Their form is a bare public form, so unmatched submissions are a problem they had to solve. Ours is
token-signed and fails closed by design (`claude.local.md` §6.7), and the value of a stored
`confirmed_at_gmt` rests on every record being bound to a verified, authorised order. 0.6.0 added the
read-only **unmatched lookups panel** instead: the merchant sees the mistyped reference, no legal
record is created, and no order can be enumerated. Do not "upgrade" this into real records without an
explicit, documented decision.

### Consents are conditional only when asked
The conditional mode added in 0.6.0 is **off by default**, so an existing checkout never changes on
update. Conditional is the better behaviour and the settings screen recommends it, but flipping the
default would silently remove consents from live checkouts. Change it only in a release where that is
the headline, and say so in the upgrade notice.

### Block checkout needs WooCommerce 8.9+
The consents use the Additional Checkout Fields API, which arrived in WooCommerce 8.9, while our
floor is 8.2. Below 8.9 the block checkout shows no consents and the classic checkout is unaffected.
When the floor rises above 8.9, the `function_exists()` guard in
`src/Frontend/BlockCheckoutConsents.php` can go.

### The receipt payload is versioned by content, not by plugin version
`ReceiptBuilder::canonical_payload()` emits `recesso-digitale/1` for a request with no consumer
self-declaration and `/2` for one that carries it. This is what lets receipts issued before 0.6.0
still recompute to their stored hash. **Any future field added to the payload must follow the same
rule**: extend the shape only for requests that actually carry the new data, and bump the schema id
with it. Never change the v1 shape.

---

## Product decisions, not engineering tasks

**Standalone mode without WooCommerce.** The competitor runs its whole plugin — form, log, emails,
receipt hash, Annex I.B model, GDPR tools — with WooCommerce absent, which roughly doubles its
addressable installs. We are HPOS-native and `wc_get_order`-everywhere, and declare
`Requires Plugins: woocommerce`. Supporting a Woo-free mode is a large architectural change and a
repositioning, not a feature. Raise it with the maintainer; do not start it from a roadmap line.

---

## Maintenance

- **PHPStan 2.x** is available (we are on 1.12). It adds level 10 and uses considerably less memory —
  our current runs need `--memory-limit=2G`. Upgrade in a release of its own, not alongside features.
- **"Tested up to" headers** in `recesso-digitale.php` and `readme.txt` must be verified against each
  new WordPress and WooCommerce release, and `.wp-env.json` pinned to the version actually tested.
- **Dompdf** is vendored and pinned; track its security advisories. Remote resource loading is
  disabled and the receipt template contains no images, SVG or custom fonts, which has limited our
  exposure to its past CVEs — keep it that way.
- **Test isolation.** The integration and e2e suites share one wp-env database. Fixtures must not
  assume they are alone in the tables: assert on deltas or search for the fixture's own order id,
  never on absolute counts or first-page position. One such flake was fixed in 0.6.0
  (`RequestRepositoryTest::test_pending_requests_are_excluded_from_the_admin_listing`).

---

## Before every release

The full checklist is `claude.local.md` §19. In practice:

```
composer run lint          # PHPCS, 0 errors
composer run analyze       # PHPStan level 8, 0 errors
composer run test          # unit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wc-reso-ordini \
  vendor/bin/phpunit -c phpunit-integration.xml.dist
npm run lint:js && npm run build && npm run test:e2e
bash bin/build-i18n.sh     # .pot + it_IT must end at 0 untranslated
bash bin/build-dist.sh
```

Then run **Plugin Check against the built zip**, not the working directory: the dev folder is named
`wc-reso-ordini` and produces false text-domain and trademarked-slug errors that do not exist in the
distribution.

Release steps are in [`RELEASING.md`](RELEASING.md).
