# Roadmap

Work not yet done, why it matters, and what it touches. Ordered by release. Rules live in
`claude.local.md`; this file records *intent*, not state — a completed item is deleted from here and
described in the `readme.txt` changelog instead.

Last reviewed: 2026-10-16, after 0.9.0.

---

## Context: where we stand

0.6.0 closed the gap opened by a feature-by-feature comparison against
[`eu-withdrawal-compliance`](https://it.wordpress.org/plugins/eu-withdrawal-compliance/) (AyudaWP),
our closest competitor on wordpress.org. We are ahead on architecture — signed HMAC guest tokens,
append-only custom tables with an audit log, a real PDF durable receipt, partial per-line withdrawal
with an atomic claims ledger, WCAG 2.2 AA, and now Checkout-block consents, which they list as
roadmap. They remain ahead on **breadth of installation** (they run without WooCommerce).

0.7.0 answered their next release: customer-facing request tracking, a mandatory-able art. 14(4)(a)
consent, consent filters, and the accessibility and repeat-visit fixes. Two of their announcements
turned out to name defects of ours that were worse than what they had fixed — a deleted withdrawal
page silently redirecting every link to the shop home, and the account tab hiding an order the moment
a request claimed it. Both are fixed. Their "sample template paragraph" fix never applied to us.

We remain behind on **admin ergonomics**, which is what most of this file is now about.

Everything below came out of that comparison unless marked otherwise.

---

## 0.10.0 — admin ergonomics and evidence

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

0.8.1 did the cheap half — `src/Support/OrderNumber.php` forgives the leading `#`, stray spaces and
zero padding, which covered the typo case. The third-party numbering case is still open, and the new
helper is where the resolver should hang.

### Tell the merchant about failed withdrawal attempts
Reported twice by the same merchant, from two directions: a mistyped order number, and an address
mistyped at checkout so the link can never reach the customer. Both already land in the read-only
"unmatched lookups" panel; what is missing is a push, so nobody has to think to go and look.

Must be a **throttled digest, never an email per event** — the lookup form is public and a bot run
would turn per-event mail into a flood aimed at the merchant's inbox. `RateLimiter` has no cooldown
primitive (it can be used with `max=1`, but that is an oblique use of a counter), so this needs a
design pass rather than a call site.
Touches `src/Frontend/FlowController.php`, `src/Persistence/LogRepository.php`, a new `WC_Email`.

### The log records failed lookups but not successful ones
`record_unmatched_lookup()` writes a row when nothing matched; a lookup that matched and sent a link
writes nothing at all — no log row, no order note, no trace anywhere but the customer's inbox. That
is backwards for reconstructing a disputed case ("I asked for the link and it never came"), and it
is what makes "did we send it?" unanswerable today. One row per sent link, alongside the digest above.

### Surface abandoned declarations
A consumer who fills in step one and never confirms leaves a `pending` row, which
`RequestRepository::admin_where()` deliberately excludes from the requests list and its counts —
correctly, because an unconfirmed declaration is not a legal record and has no `confirmed_at_gmt`.
The only trace is an order note. Worth showing, but **outside** the requests list: promoting these
rows into it would invite a merchant to process something that is not a withdrawal. A counter or a
separate panel, in the shape of the unmatched-lookups one.

---

## Later — features they have announced but not shipped

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

### The settings screen loads `wp-color-picker`, and therefore jQuery
`claude.local.md` §5 says "no jQuery". The button-colour field added in 0.8.0 uses core's
`wp-color-picker` (Iris), which depends on it. This is a deliberate, scoped exception: it is the only
control that offers the empty state the setting needs — `<input type="color">` coerces an empty value
to `#000000` and would destroy "empty means the bundled colour" on the first save — it is the picker
merchants already know from the Customizer, and it is accessible without us having to make it so. It
loads on the plugin's settings screen alone (`Menu::enqueue_assets()` gates on the settings hook
suffix), so no front-end page gains a byte of jQuery. Do not "fix" this by hand-rolling a vanilla
picker; if the exception ever has to go, the rule-clean replacement is `<input type="color">` plus an
explicit "use the bundled colour" checkbox — two controls for one setting.

### The button styling is switched off by a wrapper class, not by a template argument
`FlowController::render()` wraps the flow in `.recesso-dig-theme-buttons` when the merchant chooses to
inherit the theme's button style, and `assets/frontend/style.css` negates its own button rules with
`:not(:where(.recesso-dig-theme-buttons *))`. Both halves are deliberate. The wrapper lives outside the
templates because every flow template is theme-overridable, and a merchant who cares about button
styling is exactly the one likely to have an override — routing the toggle through `$args` would make
the setting silently do nothing for them. The `:where()` keeps the negation at zero specificity, so the
selectors stay at `(0,1,0)` and keep losing to the same theme rules they lost to before; a plain
descendant scope would raise them to `(0,2,0)` and restyle sites that changed nothing.

### The order-status check runs before the advisory/strict branch
`EligibilityEngine::evaluate()` rejects an order whose status is not in `eligible_statuses()` at its
very first guard, ahead of the advisory/strict window logic. So "Advisory — always available", which
the settings screen recommends, is **not** always available: an order in an unticked status is hard
blocked whatever the mode. This surprised a merchant testing an unpaid bank-transfer order.

It is the right order of checks — a contract in a state the merchant does not consider concluded is
not a withdrawal question — but it is not what the advisory label implies. Documented in the readme
FAQ and left alone deliberately; changing it would silently make orders withdrawable on existing
installs. If it is ever revisited, note the follow-on trap: enabling `on-hold` while in strict mode
with a delivery-based window start just swaps one dead end ("cannot be withdrawn in its current
state") for another ("the withdrawal period has not started yet"), because such an order has no
`date_completed`.

### Two emails ignore their own enable switch
The acknowledgement and the withdrawal-link emails bypass `is_enabled()`. That is deliberate — the
first is legally required, the second is the only reply to a link a guest asked for — but the code
justified it with a claim that a `WC_Email` with no saved settings reports `is_enabled() === false`.
**That claim is false**: all five of the plugin's emails report `true` before anything is saved, as
`tests/integration/Email/EmailTogglesTest.php` now pins. The comments were corrected in 0.8.1 and
each of the two says so in its `$description`, so the switch on the WooCommerce screen no longer
looks like a control that does nothing. Do not re-derive this from the WooCommerce source: the test
is the record.

### "Delivery" is the order's completion date, for every goods line
Per-line periods (0.9.0) count goods from delivery and services from the purchase, but WooCommerce
records one completion date per order and no delivery date at all, so every goods line in an order
opens on the same day. That is also what makes art. 52(2)'s special cases come out right without
modelling them: several goods delivered separately run from the *last* (the order completes when
the last one ships), and a regular supply runs from the *first* (a subscription's first order). Do
not add a per-line delivery date to approximate partial shipments: it would need a source of truth
the store does not have, and a wrong date is worse than the conservative later one.

The order-level `window_starts_at` / `window_ends_at` on `EligibilityResult` are now a summary of
the lines — earliest start, latest end, null end while any withdrawable line has not started. They
are kept for the REST response and for integrators replacing the result through
`recesso_dig_is_eligible`; anything new should read `line_windows`.

### The admin's per-item periods are computed live, not stored
The request detail (0.9.0) shows each item's period and whether the request arrived inside it,
computed from the order's dates and the product settings *as they are now*. Completing an order
later, or reclassifying a product, changes what an old request shows; the screen says so. Do not
"fix" this by snapshotting the periods into the request: the request row is the legal record, its
receipt hash covers a canonical payload, and the period is the merchant's assessment, not a fact the
consumer communicated. If a dispute needs the period as it stood, the audit log and the order's own
history are the evidence.

### The withdrawal fields live in the classic product editor and on categories only
"Withdrawal status" and "Withdrawal period starts from" are not offered in Quick Edit, Bulk Edit or
WooCommerce's block-based product editor. Categories are the intended way to configure many
products at once, and both fields inherit from them, so a catalogue never needs product-by-product
editing. If the block editor becomes WooCommerce's default, the fields must follow it there (a
`woocommerce_block_template_register` extension), for both keys together.

### Multilingual: a translation inherits, it does not copy
WPML and Polylang translations read the original's withdrawal status and period basis at lookup time
(`src/Integration/Multilingual.php`); nothing is copied onto the translation. A value saved on the
translation still wins. Do not add a `wpml-config.xml` `custom-fields` "copy" rule for these keys:
it would overwrite a deliberate per-language exception on every sync, and Polylang's copy would do
the same. WPML is detected through its `wpml_original_element_id` filter (term lookups go through
`term_taxonomy_id`, as WPML stores them); Polylang has no source relation, so its default language
stands for the original.

### No unverified withdrawal records
The competitor's headline 2.1.0 feature registers requests that match no order, flagged "Unverified".
Their form is a bare public form, so unmatched submissions are a problem they had to solve. Ours is
token-signed and fails closed by design (`claude.local.md` §6.7), and the value of a stored
`confirmed_at_gmt` rests on every record being bound to a verified, authorised order. 0.6.0 added the
read-only **unmatched lookups panel** instead: the merchant sees the mistyped reference, no legal
record is created, and no order can be enumerated. Do not "upgrade" this into real records without an
explicit, documented decision.

### The account tab shows the latest request per order, from the last 25 orders
`AccountEndpoint::rows()` walks the customer's 25 most recent orders and reads
`RequestRepository::latest_for_order()` for each. That deliberately avoids a `customer_id` column, a
migration and a backfill, and it keeps the page bounded — but it means an order withdrawn from twice
shows only the later request, and an order older than the customer's 25 most recent is not listed.

Both are acceptable while a withdrawal window is measured in days. If a full per-customer history is
ever wanted, it needs a nullable `customer_id` column on the requests table, an index, a batched
backfill through Action Scheduler, and a repository method keyed on it — not a wider `wc_get_orders()`
limit, which would make the page cost grow with order history.

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
- **`EligibilityAdapter` memoises per order for the life of the request**, which is correct in
  production — a page load evaluates each order once — and a trap in tests. A test that creates a
  withdrawal and then asks the *same* container whether the order is still eligible gets the answer
  computed before the claim existed, so it passes whatever the code does. Build a fresh `Container`
  for the "second page load", as `AccountEndpointTest::render()` and `AbandonedDeclarationTest` do.
  This is exactly the kind of silent pass that §19's "watch it fail first" rule exists to catch.

---

## Before every release

The full checklist is `claude.local.md` §19. In practice:

```
composer run lint          # PHPCS, 0 errors
composer run analyze       # PHPStan level 8, 0 errors
composer run test          # unit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wc-reso-ordini \
  -- php vendor/bin/phpunit -c phpunit-integration.xml.dist
npm run lint:js && npm run build && npm run test:e2e
bash bin/build-i18n.sh     # .pot + it_IT must end at 0 untranslated
bash bin/build-dist.sh
```

Then run **Plugin Check against the built zip**, not the working directory: the dev folder is named
`wc-reso-ordini` and produces false text-domain and trademarked-slug errors that do not exist in the
distribution.

Release steps are in [`RELEASING.md`](RELEASING.md).
