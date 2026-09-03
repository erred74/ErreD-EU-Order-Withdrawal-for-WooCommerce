# Roadmap

Work not yet done, why it matters, and what it touches. Ordered by release. Rules live in
`claude.local.md`; this file records *intent*, not state — a completed item is deleted from here and
described in the `readme.txt` changelog instead.

Last reviewed: 2026-09-03, after 0.8.0.

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

## 0.9.0 — admin ergonomics and evidence

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
