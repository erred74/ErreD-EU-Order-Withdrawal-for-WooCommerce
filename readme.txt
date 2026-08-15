=== ErreD EU Order Withdrawal for WooCommerce ===
Contributors: draison, recessodigitale
Tags: woocommerce, withdrawal, recesso, cancellation, consumer
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 8.2
WC tested up to: 11.0
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Digital withdrawal function for WooCommerce — the EU "easy withdrawal" duty (Directive 2023/2673), in force from 19 June 2026.

== Description ==

From 19 June 2026, EU Directive 2023/2673 requires online stores across the European Union to
provide a **digital withdrawal function**: a way to cancel a distance contract online that is at
least as easy to use as the purchase flow itself. A single "cancel" button is not enough. The law
requires a clearly labelled, continuously available function, a two-step confirmation, and an
acknowledgement on a durable medium whose timestamp fixes the legal moment of communication.

ErreD EU Order Withdrawal for WooCommerce implements that function end to end — not just the
button, but the declaration flow, the two-step confirmation, the durable-medium receipt, the
eligibility rules and the merchant review tools needed to actually comply.

It ships the Italian transposition out of the box (art. 54-bis of the Codice del Consumo,
introduced by D.Lgs. 209/2025) with the legally-fixed label «recedere dal contratto qui», and it
is fully translatable for other EU markets.

The plugin does not create a right of withdrawal: it provides the online channel to exercise an
existing one, honouring the legal exceptions (e.g. art. 59 in Italy). It is built security-first,
is WooCommerce High-Performance Order Storage (HPOS) native, and works fully offline (no external
service calls).

**For consumers**

* A clearly labelled, continuously available withdrawal function on the My Account orders screen and
  in a dedicated "Right of withdrawal" tab listing every order still eligible.
* A two-step declaration and confirmation flow ("conferma recesso"), server-rendered so it works
  even with JavaScript disabled.
* An acknowledgement on a durable medium (email plus a stored PDF receipt) whose timestamp fixes
  the moment of communication — the legal start date (dies a quo) for refund deadlines.
* Reachable by guest-checkout customers through a per-order signed link in their order emails and on
  the order-received page, with no order enumeration.

**For merchants**

* A React admin screen to review requests, filter by status, search by order, name or email, view
  the audit timeline, view/regenerate the durable receipt and mark requests refunded or rejected.
* A menu badge counting the requests awaiting action.
* A conservative, configurable eligibility engine (withdrawal window, start trigger, eligible order
  statuses, per-product and per-category exclusions) that fails closed when configuration is missing.
* Article 16 checkout consents — digital content (art. 16(m)) and early-started services
  (art. 14(4)(a)) — in both the classic checkout and the WooCommerce Checkout block, shown either on
  every checkout or only for the carts that actually call for them.
* Role-based access to the requests screen, so the personal data each request holds is visible to
  the people you choose rather than to everyone who can edit the shop.
* Optional WooCommerce Subscriptions support: a confirmed withdrawal cancels the subscription
  (status transition only — subscription data is never deleted).
* An append-only audit trail and tamper-evident receipts (SHA-256 of the receipt payload).

**Built to standard**

* Security-first: capability + nonce/REST permission checks and input sanitisation / output
  escaping on every privileged path; signed, rate-limited guest access.
* Accessibility: WCAG 2.2 AA, verified with automated axe checks.
* Fully translatable; ships a complete Italian (it_IT) translation.

This plugin encodes legal and security intent; it is not legal advice. The mapping of the art. 59
exceptions to your catalogue and the durable-medium content must be validated by a qualified legal
professional before relying on them.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress
   plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. WooCommerce 8.2 or newer (with High-Performance Order Storage) is required.
4. Visit WooCommerce → Recesso digitale: settings to configure the withdrawal window, the window
   start trigger and your product/category exclusions.

== Source code and build process ==

This plugin ships its complete, human-readable source. The compiled assets in `build/` are
generated from the React/JS/CSS source in `assets/` with @wordpress/scripts.

* JS/CSS source: `assets/admin/` and `assets/frontend/`
* Generated bundles: `build/admin/` and `build/frontend/`
* Rebuild the bundles: `composer install && npm ci && npm run build`
* Runtime PHP dependencies (Dompdf) are managed with Composer.

Development repository: https://github.com/erred74/ErreD-EU-Order-Withdrawal-for-WooCommerce

== Frequently Asked Questions ==

= Does this plugin decide whether an order can be withdrawn? =

No. The plugin provides the channel and records the request; the merchant accepts or rejects each
one. The ordinary 14-day period is shown as advisory information (it does not hide the function),
and the merchant can pre-exclude specific products or categories (art. 59). Rejecting a request
requires a reason, which is recorded and emailed to the consumer. The mapping of the art. 59
exceptions to your catalogue must still be validated by a legal professional.

= Does it work for guest-checkout orders? =

Yes. Guests receive a per-order, single-purpose signed link (HMAC, verified in constant time and
rate-limited). A bare order id or order key is never sufficient to submit a withdrawal, and
responses are uniform to prevent order enumeration.

= Does the withdrawal flow require JavaScript? =

No. The two-step flow is server-rendered and works with JavaScript disabled; JavaScript only
enhances the admin experience.

= What is the "durable medium"? =

A withdrawal acknowledgement sent by email together with a stored PDF receipt, kept in a protected
location and downloadable only through a capability- or token-checked endpoint. The receipt records
the content of the request and the exact date and time of transmission.

= Do the checkout consents work with the Checkout block? =

Yes. They are registered through WooCommerce's Additional Checkout Fields API, so WooCommerce renders,
validates and stores them natively in the block checkout — no extra JavaScript is loaded. That API
arrived in WooCommerce 8.9: on older versions the block checkout simply shows no consents, and the
classic `[woocommerce_checkout]` shortcode keeps working on every supported version.

= Can a consent appear only for the products that need it? =

Yes. Enable "Show each consent only when the cart contains a product classified for it" under Checkout
consents. Each consent then follows the "Withdrawal status" you set on the product or its category:
the digital-content box appears only with art. 16(m) items in the cart, the service box only with
art. 14(4)(a) items. The option is off by default, so updating the plugin never changes what your
checkout shows.

= Does it support WooCommerce Subscriptions? =

Optionally. If WooCommerce Subscriptions is active, a confirmed withdrawal cancels the related
subscription by transitioning its status — it never deletes subscription data. With Subscriptions
absent, the plugin is unaffected.

= Does the plugin make external network calls? =

No. It functions fully offline and loads no third-party scripts, fonts or assets.

= What happens to my data on uninstall? =

Nothing is removed unless you opt in via the "Delete all data on uninstall" setting. When enabled,
the plugin removes its tables, options and the flow page on uninstall.

== Screenshots ==

1. Step one: the withdrawal declaration ("recedere dal contratto qui"), pre-filled from the order.
2. Step two: the explicit "conferma recesso" confirmation.
3. The acknowledgement screen shown after confirmation.
4. The admin requests screen: status filter, free-text search and per-request actions.
5. The request detail: audit timeline, durable-medium receipt (PDF) and status processing.

== Changelog ==

= 0.6.1 =
* Fix: opening a durable-medium receipt from the request detail panel failed with "You are not
  authorized to perform this action". The link was built with a helper that HTML-escapes the URL it
  returns — correct when printing into a page, wrong for a value handed to the admin app as data —
  so the browser sent `amp;request` instead of `request`, the endpoint saw no request id and refused.
  Present since 0.5.1, and it affected every receipt opened from that panel, not only older ones. The
  Download link in the no-JavaScript requests table was never affected.
* A receipt link that cannot be matched to a withdrawal record no longer reports an authorisation
  failure. Merchants are told the record could not be read and pointed at the requests screen, where
  a pending database upgrade finishes; everyone else still gets the same generic refusal, so the
  endpoint cannot be used to discover which requests exist.
* A merchant following an old receipt link — from an acknowledgement email, or a bookmark, whose
  signed token has since expired — is now taken to that request in the admin instead of a permissions
  error. The file itself still requires a freshly signed link.

= 0.6.0 =
* Checkout consents now work in the **WooCommerce Checkout block**, not only the classic shortcode
  checkout. They are registered through WooCommerce's Additional Checkout Fields API, so the
  checkboxes are rendered, validated and stored by WooCommerce itself — accessible markup and
  block-theme styling included. Requires WooCommerce 8.9 or newer for the block checkout; the
  classic checkout is unchanged and works on every supported version.
* New option: show each consent **only when the cart contains a product classified for it** (the
  digital-content box with art. 16(m) items, the service box with art. 14(4)(a) items), using the
  "Withdrawal status" already set on the product or category. Off by default, so nothing changes on
  update; turn it on under Checkout consents.
* New "Right of withdrawal" tab in My Account, listing the customer's currently eligible orders with
  the «recedere dal contratto qui» control for each. Can be switched off under Withdrawal link
  visibility.
* New `[recesso_digitale_link]` shortcode (attributes: `text`, `class`) for footers, widgets and
  menus, and `[recesso_digitale_avviso_esclusione]` for product templates built with a page builder
  (Divi, Elementor, Bricks and similar) whose layouts do not fire the usual WooCommerce hook.
* The settings screen is considerably more configurable, and every field now says whether it is
  mandatory, recommended or optional:
  * which **order statuses** offer the withdrawal function (a checkbox list of every registered
    status, previously fixed to Processing and Completed);
  * which **roles**, besides the administrator, may view and manage requests — access is granted and
    revoked the moment you save, and is never offered to customer-facing roles;
  * a **From name** and **From address** for the plugin's own emails only;
  * the wording of the **accepted, rejected and completed** customer emails;
  * the **intro paragraph** above the public form (text and on/off);
  * an optional **"bought as a consumer" self-declaration** on the form, recorded verbatim in the
    durable-medium receipt for B2B disputes;
  * separate **title and body for each exclusion notice** — digital content (art. 16(m)), dated
    services (art. 16(l)) and the other art. 16 exceptions — with a `{withdrawal_page_link}`
    placeholder.
* The requests screen now lists **recent unmatched lookups**: attempts to request a withdrawal link
  whose order number and email matched no order, so a mistyped reference is visible instead of
  vanishing silently. No withdrawal record is created for them, and the submitted address is stored
  masked.
* Fix: a decision taken through the request detail panel's "Set status" dropdown — the main path
  since 0.5.0 — wrote no order note. Accepting, rejecting, completing and resetting a decision now
  all appear in the order timeline.
* Fix: the "default policy for unconfigured products" setting declared one default and applied
  another. Both are now "Allow withdrawal", which is what running sites have always used.
* Fix: the settings screen and the CSV export required `manage_woocommerce` while the requests list
  required the plugin's own capability. All three now use the plugin capability, which the new roles
  setting controls.
* Fix: an opted-in "delete all data on uninstall" left two options behind.
* Hardening: an in-place upgrade whose table can no longer take another column (InnoDB "row size too
  large", possible after several upgrades) now rebuilds the table and completes, instead of leaving
  a half-applied schema that breaks the requests screen until a manual re-activation.
* Every merchant-editable text is now exposed to WPML and Polylang String Translation; the previous
  configuration file covered only five of them.
* Database: one new optional column (`consumer_declaration`), applied automatically. Receipts issued
  before this release keep verifying against their stored hash — the receipt payload is versioned,
  and a request without a self-declaration hashes exactly as it did before.

= 0.5.10 =
* Compatibility with WordPress 7.1 and WooCommerce 11. Verified against the WordPress 7.1 release
  candidate with WooCommerce 11.0.1: full integration and end-to-end suites, including the
  accessibility checks, pass unchanged.
* Admin: the requests screen now opts in explicitly to the 40px control size that WordPress 7.1
  makes the default, so the status filter, the search field and the action buttons keep their
  intended alignment on every supported WordPress version instead of changing height silently.
* Security: the bundled Dompdf library, used to render the durable-medium receipt, is updated from
  3.1.5 to 3.1.6. That release fixes six reported issues, including a local file read and a
  file-existence disclosure through SVG images embedded as data URIs, and two denial-of-service
  paths through oversized image bitmaps. This plugin never enabled remote resource loading and its
  receipt template contains no images, SVG or custom fonts, so exposure was limited; the library is
  updated regardless.
* Admin: the requests bundle is now loaded with the deferred script strategy.
* The minimum supported WordPress version is now 6.9, matching the minimum required by the
  WooCommerce releases this plugin is built against. Nothing in the plugin itself required the
  change: WooCommerce 11 already refuses to run below WordPress 6.9, so the previous floor of 6.7
  could not be satisfied in practice.
* No database migration and no changes to the withdrawal flow, the durable-medium receipt or the
  legal timestamps.

= 0.5.9 =
* Fix: the order-lookup form never actually sent the withdrawal-link email. WooCommerce does not
  autoload its WC_Email base class, so on the front-end request the send bailed out before the email
  was built. The mailer is now initialised first, so the link is delivered.
* Order-lookup: the link is emailed whenever the submitted order number and email match, regardless
  of the order's current eligibility — a legitimate consumer always gets a response on their own
  address, and the declaration screen explains any ineligibility (e.g. an expired window) when the
  link is opened.

= 0.5.5 =
* Withdrawal page: visiting the page without a signed link (e.g. via the persistent footer link) now
  shows an order-lookup form instead of a blank page. The consumer enters their order number and the
  email used on the order, and the plugin emails a secure, signed withdrawal link to that order's
  address. The link is never rendered inline and the response is always uniform, so orders cannot be
  enumerated; requests are rate-limited per IP and protected by a honeypot.
* New "Withdrawal link request" email (WooCommerce → Settings → Emails) carries the requested link.

= 0.5.4 =
* Admin: the WooCommerce menu entries and the requests-page heading now default to English
  ("Order Withdrawal", "Order Withdrawal: settings"); Italian sites keep the previous
  «Recesso digitale» labels via the bundled it_IT translation.

= 0.5.3 =
* Uninstall: only the withdrawal page auto-created by the plugin is removed. The page is now
  identified by a created-by-plugin marker (and must still host the withdrawal shortcode), so a
  pre-existing page the merchant selected in settings is never deleted.

= 0.5.2 =
* The withdrawal function is now a single «recedere dal contratto qui» button shown below the order
  details, available to both guest-checkout customers (order-received page) and logged-in members
  (My Account order view); the previous duplicate button is gone and the link no longer reports
  "this withdrawal link is not valid or has expired".
* Confirmation step: removed the (unnecessary) product thumbnail and gave the «conferma recesso»
  button a proper, accessible button style that no longer depends on the theme.
* Admin: unconfirmed (abandoned) declarations no longer appear in the requests list or its counts,
  and a consumer who closed the page before confirming can start a fresh request.
* Admin: the menu badge counts all open requests awaiting action (confirmed and acknowledged) and
  uses the standard WooCommerce/WordPress menu-counter styling.

= 0.5.1 =
* Packaging: the distribution now bundles the full, human-readable JS/CSS source (`assets/`) and
  the build manifests (`package.json`, `package-lock.json`) alongside the compiled `build/`
  assets, and the readme documents how to rebuild them and links the public development repository.
* Dependency: the bundled Dompdf library is updated from 3.0.0 to 3.1.5.
* Hardening: the admin receipt download link now carries a nonce that the download endpoint
  verifies for the admin path (the consumer link continues to use its signed, rate-limited token).
* Hardening: saving a product's withdrawal status now verifies the WooCommerce editor nonce and
  the `edit_product` capability explicitly.

= 0.5.0 =
* New "Withdrawal" column on the WooCommerce orders screen (HPOS and the legacy list) showing each
  order's current withdrawal decision as a colour-coded badge.
* The request detail panel now sets the decision through a single "Set status" dropdown (Pending,
  Accepted, Rejected, Completed) with a "Save status" button next to "Regenerate receipt". The reason
  field appears only when rejecting (and stays required); the orders column updates to match.
* Hardening: the contract reference stored for a withdrawal is now always derived server-side from
  the order number, ignoring any client-supplied value on the REST create endpoint.
* Hardening: the receipt download endpoint now validates the stored path against an exact directory
  boundary, preventing a similarly named sibling directory from being treated as the protected store.
* Packaging: reproducible production builds (installed from composer.lock) and a direct-access guard
  added to the generated PHP asset files in the distribution.

= 0.4.0 =
* Product/category "Withdrawal status" now offers the specific art. 59 / Directive classifications
  (standard, digital content art. 16(m), service started early art. 14(4)(a), dated accommodation /
  transport art. 16(l), other art. 16 exception) instead of a plain allow/exclude, and each maps to
  the right eligibility outcome. Statuses saved by earlier versions keep working.
* A one-time welcome notice after activation links straight to the settings and the auto-created
  withdrawal page.
* Reorganised the settings screen into clear sections (General, Withdrawal deadline, Article 16
  exclusions, Checkout consents, Model withdrawal form, Excluded products notice, Withdrawal link
  visibility, Data), added a notification email and a withdrawal-page selector, a "show model form"
  toggle and an optional trader phone number.
* The Annex I.B model withdrawal form was redesigned to match the statutory layout (header block with
  the trader's details, fillable lines, source attribution and an optional printable view).

= 0.3.1 =
* Fix: an in-place update now reliably adds the v4 optional columns — the installed schema version is
  only advanced once the columns actually exist, so the requests admin list and the withdrawal form no
  longer break until a manual re-activation.
* Hardening: the withdrawal declaration never white-screens — a transient persistence failure degrades
  to a friendly message instead of a fatal error (the legally-required function stays usable).

= 0.3.0 =
* Art. 59 configuration: per-product and per-category "Right of withdrawal" status (allow / exclude /
  inherit) in the product and category editors, plus an opt-in "excluded from withdrawal" notice on
  the product page.
* Checkout consents: optional, configurable digital-content (art. 16(m)) and service-start
  (art. 14(4)(a)) consents, recorded on the order with timestamps and an order note.
* Annex I.B model withdrawal form (printable), populated with configurable trader contact details,
  shown below the public form and via the [recesso_digitale_modulo] shortcode.
* Order notes mirror the withdrawal lifecycle; new customer emails on accept/refund/complete and an
  admin notification (multiple recipients) when a withdrawal is confirmed.
* Admin: Accept / Mark completed / Mark refunded actions, an at-a-glance stats summary and a CSV
  export of requests.
* GDPR: personal-data exporter and eraser (anonymises personal data while retaining the legal
  acknowledgement) and a suggested privacy-policy snippet.
* Optional refund IBAN and reason fields on the declaration (carried into the durable receipt),
  honeypot anti-spam, an optional persistent footer link, an optional strict deadline-enforcement
  mode with grace days, and a wpml-config.xml for WPML/Polylang.

= 0.2.0 =
* Redesigned the consumer withdrawal page: products are chosen with checkboxes, each shown with its
  thumbnail, and a per-product quantity selector appears only when more than one unit was purchased.
* The durable-medium acknowledgement email now lists every selected product with its quantity, along
  with the confirmation email and the declaration timestamp, matching the PDF receipt.
* The two-step confirmation («conferma recesso») and the completion screen now itemise exactly which
  products and quantities are being withdrawn.
* Internal: item resolution unified in one shared component used by the receipt, email and on-screen
  summaries; the tamper-evident receipt hash is unchanged.

= 0.1.0 =
* Initial development release.
* Security-first, HPOS-native withdrawal channel: continuously available function, two-step
  server-rendered declaration/confirmation flow, signed rate-limited guest access.
* Durable medium: acknowledgement email plus a protected, tamper-evident PDF receipt with a
  write-once legal timestamp (dies a quo), generated asynchronously via Action Scheduler.
* Conservative, configurable, fail-closed eligibility engine (window, start trigger, art. 59
  exclusions) with filters for integrators.
* React admin: list, status filter, free-text search, audit timeline, receipt view/regenerate,
  process actions, and a menu badge for requests awaiting action.
* Optional WooCommerce Subscriptions adapter (withdrawal cancels the subscription).
* Merchant-decides model: the 14-day period is advisory (shown to the merchant), the withdrawal
  function stays available, and the merchant accepts or rejects each request. Rejection requires a
  reason, recorded in the audit trail and emailed to the consumer.
* Accessibility to WCAG 2.2 AA (axe-verified) and a complete it_IT translation.

== Upgrade Notice ==

= 0.6.1 =
Fixes the durable-medium receipt failing to open from the request detail panel with "You are not
authorized to perform this action". Affected every receipt opened there, on 0.5.1 and later.
Recommended for all sites. No data migration required.

= 0.6.0 =
Checkout consents now work in the Checkout block, My Account gains a "Right of withdrawal" tab, and
the settings screen is far more configurable. Fixes missing order notes on decisions taken from the
"Set status" dropdown. Adds one optional database column, applied automatically.

= 0.5.10 =
Compatibility release for WordPress 7.1 and WooCommerce 11, with the admin screen aligned to the
new 40px control size. Also updates the bundled Dompdf library to 3.1.6, which carries security
fixes. Recommended for all sites. No data migration required.

= 0.5.4 =
Admin menu entries now default to English for non-Italian sites; Italian labels are unchanged via
the bundled translation. No data migration required.

= 0.5.3 =
Safer uninstall: only the page the plugin itself auto-created can be removed when deleting data on
uninstall; a page you selected yourself in settings is never touched. No data migration required.

= 0.5.2 =
A single, correctly-working withdrawal button for guests and members, a tidier styled confirmation
step, and admin improvements: abandoned (unconfirmed) requests are hidden and restartable, and the
menu badge counts all open requests with the standard styling. No data migration required.

= 0.5.1 =
Bundles the full JS/CSS source alongside the compiled assets and links the public development
repository, updates the bundled Dompdf to 3.1.5, and hardens the admin receipt download and the
product withdrawal-status save. No data migration required.

= 0.5.0 =
Adds a withdrawal-status column to the orders screen and replaces the request detail panel's action
buttons with a single "Set status" dropdown plus a "Save status" button. No data migration required.

= 0.4.0 =
Richer per-product/category withdrawal status (art. 59 exceptions), a post-activation welcome notice, a
reorganised settings screen, and a redesigned Annex I.B model form. No data migration required.

= 0.3.1 =
Fixes the in-place schema upgrade (empty requests list / blank withdrawal submission after updating to
0.3.0) and prevents the withdrawal form from white-screening on a transient error.

= 0.3.0 =
Adds art. 59 product/category configuration, checkout consents, the Annex I.B model form, status
emails, admin actions/stats/CSV export, GDPR tools and more. Adds two optional database columns
(applied automatically). Review the new settings under Recesso digitale → Settings.

= 0.2.0 =
Clearer withdrawal page (checkbox product picker with thumbnails and per-product quantities) and a
fuller durable-medium acknowledgement that lists the selected products. No data migration required.

= 0.1.0 =
Initial release.
