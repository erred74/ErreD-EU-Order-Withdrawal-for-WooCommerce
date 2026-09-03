=== ErreD EU Order Withdrawal for WooCommerce ===
Contributors: draison, recessodigitale
Tags: woocommerce, withdrawal, recesso, cancellation, consumer
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 8.2
WC tested up to: 11.0
Stable tag: 0.8.0
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

Either consent can also be made a condition of placing the order, and both are off by default. For the
Article 14(4)(a) service-start consent that default is deliberate: asking for the service to begin
inside the withdrawal period is the customer's request to make, and it only entitles you to a
proportionate payment if they later withdraw. Require it only where your service always begins inside
that window — a live session, a booking for the next few days — so an order without the request is one
you cannot fulfil.

= Can customers see the requests they have sent? =

Yes. The My Account "Right of withdrawal" tab lists them with the date they were sent, the order, the
scope, the current status, any note you wrote when deciding, the receipt verification code and a link
to the receipt PDF that keeps working for as long as the account does. Orders they can still withdraw
from are listed in the same table. The tab is on by default and can be turned off under Withdrawal
link visibility. Guest-checkout customers have no account, so their route stays the signed link in
their emails.

= My withdrawal links stopped working. What happened? =

Most likely the page hosting the withdrawal form was deleted, moved to the trash or unpublished. When
that happens the plugin stops showing withdrawal links rather than pointing them somewhere useless,
and tells you so on the settings screen and in an admin notice. Select or restore the page under
General and the links come back.

= Does it support WooCommerce Subscriptions? =

Optionally. If WooCommerce Subscriptions is active, a confirmed withdrawal cancels the related
subscription by transitioning its status — it never deletes subscription data. With Subscriptions
absent, the plugin is unaffected.

= Does the plugin make external network calls? =

No. It functions fully offline and loads no third-party scripts, fonts or assets.

= What happens to my data on uninstall? =

Nothing is removed unless you opt in via the "Delete all data on uninstall" setting. When enabled,
the plugin removes its tables, options and the flow page on uninstall.

= Can I change the wording on the withdrawal page? =

Yes, in three ways, and you will usually only need the first. The four texts on the order lookup screen — the page title, the intro paragraph, the hint under the email field and the submit button label — are settings, under WooCommerce → Order Withdrawal: settings → Order lookup screen. The intro of the declaration form, the consumer self-declaration, the excluded-product notices and the status emails have their own fields on the same screen. Leave any of them empty to keep the bundled wording, which follows the language of each visitor; your own text is shown exactly as written, in every language, and can be translated per language with WPML or Polylang.

For anything else — field labels, the confirmation step, the acknowledgement screen — use a translation editor such as Loco Translate, which edits the plugin's strings and stores your version outside the plugin so updates do not overwrite it. For full control of the markup, copy any file from the plugin's `templates/frontend/` into a `recesso-digitale/` folder in your theme (for example `wp-content/themes/your-child-theme/recesso-digitale/lookup.php`) and edit it there; the plugin uses your copy automatically. A template copied before 0.8.0 keeps its own hardcoded wording and will ignore the settings above — re-copy it if you want both.

= Can I change the colour of the withdrawal button? =

Yes, under WooCommerce → Order Withdrawal: settings → Withdrawal page appearance. Pick any colour and the plugin works out the hover shade and the label colour from it, so the label keeps a WCAG AA contrast ratio whatever you choose. Leave it empty for the bundled colour. If your theme styles its buttons the way you want them everywhere, choose "Inherit my theme's button style" instead and the plugin stops styling them at all. The «recedere dal contratto qui» link in My Account and in your order emails is not affected: it sits inside your theme's own pages and has always used your theme's button style.

= Why does the customer receive a link instead of sending the request straight away? =

Because the withdrawal function must work for guest-checkout customers, who have no account to log into. If the lookup screen simply accepted "order number plus email" and recorded a withdrawal, anyone could file withdrawals against orders that are not theirs, and could discover which order numbers exist by trying them. That matters more here than on an ordinary form: the moment a withdrawal is confirmed is a legal fact — the date from which your refund deadline runs — so a request you cannot attribute is a bad record, not just a nuisance.

So the plugin checks that the order number and the email match, then emails a cryptographically signed, expiring link to the address on the order itself, never to the address typed in the form. Opening that link proves the person has the order's mailbox, and the request is bound to a verified order. The screen answers identically whether or not an order matched, so it reveals nothing. Customers who arrive from the link in their order emails, or from the My Account tab, already hold a signed link and never see this step.

== Hooks for developers ==

All hooks are prefixed `recesso_dig_`. Names and signatures are stable within a major version.

Filters:

* `recesso_dig_is_eligible` — `( EligibilityResult $result, WC_Order $order )`. The last word on
  whether an order can be withdrawn from. Use it to refine the decision for your catalogue.
* `recesso_dig_withdrawable_statuses` — `( string[] $statuses )`. Which order statuses a withdrawal
  may be started from, after the setting has been applied.
* `recesso_dig_entry_token_ttl` — `( int $seconds )`. Lifetime of the signed link emailed to
  guest-checkout customers. Default 60 days, floor of one day.
* `recesso_dig_consent_applies` — `( bool $applies, string $consent )` where `$consent` is `digital`
  or `service`. Decides per cart whether a consent is asked for. Both checkouts read this same
  decision, so the classic checkout and the Checkout block cannot disagree.
* `recesso_dig_consent_required` — `( bool $required, string $consent )`. Makes a consent blocking or
  optional. Must not depend on the cart: the Checkout block registers its fields once per request,
  before any cart is known.
* `recesso_dig_consent_render_hook` — `( string $hook )`. Moves the consent checkboxes to another
  checkout hook; return an empty string to suppress the render and place them yourself. Classic
  checkout only — in the Checkout block, WooCommerce decides placement.
* `recesso_dig_consent_render_priority` — `( int $priority, string $hook )`. Classic checkout only.

Actions:

* `recesso_dig_request_created` — `( WithdrawalRequest $request )`. After step one is stored, before
  the consumer has confirmed. Not yet a legal record.
* `recesso_dig_request_confirmed` — `( WithdrawalRequest $request )`. After step two. This is the
  moment of communication; `confirmed_at_gmt` is set and never changes.
* `recesso_dig_request_processed` — `( int $request_id, string $action )`. After a merchant decision.
* `recesso_dig_after_declaration_form` — fires inside the flow container, below the declaration form.

== Screenshots ==

1. Step one: the withdrawal declaration ("recedere dal contratto qui"), pre-filled from the order.
2. Step two: the explicit "conferma recesso" confirmation.
3. The acknowledgement screen shown after confirmation.
4. The admin requests screen: status filter, free-text search and per-request actions.
5. The request detail: audit timeline, durable-medium receipt (PDF) and status processing.

== Changelog ==

= 0.8.0 =
The withdrawal page is now yours to word and to colour, without touching code or waiting for a translation.

* **New:** the four texts on the order lookup screen — page title, intro paragraph, the hint under the email field, and the submit button label — are settings, under WooCommerce → Order Withdrawal: settings → Order lookup screen. Until now they were the only customer-facing copy in the plugin with no field of its own, which meant the first screen many customers see was the one screen a merchant could not reword. Leave a field empty to keep the bundled sentence, which follows each customer's language; your own wording can be translated per language with WPML or Polylang.
* **New:** a colour for the withdrawal page's buttons, under Withdrawal page appearance. Pick any colour and the plugin works out the hover shade and the label colour from it, so the label keeps a WCAG AA contrast ratio whatever you choose — a pale brand colour cannot leave you with white text nobody can read.
* **New:** "Inherit my theme's button style", in the same section, for stores whose theme already styles its buttons the way they want them everywhere. The plugin then stops styling those buttons entirely. It stays off by default: the withdrawal page is an ordinary page, where a theme's button rules are not guaranteed to load, and the shipped styling is what keeps the control from rendering as bare text.
* **Fix:** with "Delete all data on uninstall" enabled, the wording you had written for the dated-service exclusion notice (Art. 16(l)) was left behind in the database instead of being removed. Two rows, no effect on how the site ran, but the setting promises a clean removal and did not deliver one. The plugin's own version marker was left behind for the same reason. Present since 0.6.0, and now covered by a test that checks every setting the plugin writes against the list of what it deletes.

= 0.7.0 =
Customers can now follow their withdrawal requests from their account, and a withdrawal page that has
been deleted or unpublished no longer fails silently.

* **New:** the My Account "Right of withdrawal" tab lists the requests the customer has sent — when it
  was sent, the order, the scope, the current status, the note you wrote when you decided it, and the
  receipt verification code — alongside the orders they can still withdraw from. Previously the tab
  listed eligible orders only, so sending a request made the order disappear from it and left the
  customer reading "None of your orders is currently eligible for withdrawal". The acknowledgement and
  status emails link to the same screen.
* **New:** the receipt PDF is reachable from that tab for as long as the account exists. The link in
  the acknowledgement email is signed with a token that expires after 60 days, and until now that was
  the only route to it — a durable medium the consumer can no longer open is not durable.
* **New:** the My Account orders list shows the withdrawal control on an eligible order, and the
  request's status on an order already withdrawn from. It showed neither before.
* **New:** the Article 14(4)(a) service-start consent can be made mandatory (Withdrawals → Settings →
  Checkout consents). Off by default, because asking for the service to start inside the withdrawal
  period is the customer's choice to make; useful where the service always begins inside that window,
  such as live sessions or bookings.
* **New:** four developer filters for the checkout consents — `recesso_dig_consent_render_hook` and
  `recesso_dig_consent_render_priority` move the checkboxes on the classic checkout (an empty hook
  suppresses the render), `recesso_dig_consent_applies` decides per cart whether each consent is
  asked for, and `recesso_dig_consent_required` makes either one blocking or optional. All hooks are
  now documented under "Hooks for developers" below.
* **New:** an "Edit your details" link on the confirmation step, back to the form. Re-submitting
  replaces the unconfirmed request rather than adding a second one.
* **Fix:** a withdrawal page that was deleted, trashed or unpublished sent every withdrawal link to
  the shop front page instead — customers arrived with a valid link, no form and no explanation. Links
  are now suppressed rather than misdirected, and the settings screen and an admin notice say what is
  wrong with the page. The settings screen also stops describing your home page as the withdrawal form
  when no page is selected.
* **Fix:** an expired or already-used link submitted from a stale form produced an unstyled "You are
  not authorized to perform this action" page outside your theme, with no way back. It now explains
  what happened on the withdrawal page and offers the form that sends a fresh link.
* **Fix:** returning to the confirmation step for a request already sent re-showed the success screen
  as though the withdrawal had just been recorded. It now says the request is already on record and
  when it was sent. Reaching the form again for an order already withdrawn from says the same, instead
  of a bare "a request is already in progress".
* **Fix:** the message screen printed whatever text the address bar carried. It was correctly escaped,
  so it was never a scripting hole, but it did let a crafted link display wording of someone else's
  choosing inside your withdrawal page. Only messages this plugin defines can be shown now.
* **Fix:** accessibility of the public form — the consumer declaration told screen readers to announce
  its own label twice, the order-lookup fields had no description attached, and a validation error was
  not moved to on re-render.
* Two links in the requests screen were built with a helper that HTML-escapes the URL it returns. That
  is correct when printing a link into a page, and wrong for a URL handed to the admin app as data and
  set straight onto a button: the browser then sent `amp;request` instead of `request`, so the server
  never saw the parameters and refused. Both are fixed and covered by tests that fail against the old
  form.
* **Fix:** **Export CSV** failed with "The link you followed has expired". The export nonce never
  reached the server. Present since 0.3.0.
* **Fix:** opening a durable-medium **receipt** from the request detail panel failed with "You are not
  authorized to perform this action". Present since 0.5.1, and it affected every receipt opened from
  that panel, whatever the age of the withdrawal. The Download link in the no-JavaScript requests
  table was never affected by either bug.
* A receipt link that cannot be matched to a withdrawal record no longer reports an authorisation
  failure. Merchants are told the record could not be read and pointed at the requests screen, where
  a pending database upgrade finishes; everyone else still gets the same generic refusal, so the
  endpoint cannot be used to discover which requests exist.
* A merchant following an old receipt link — from an acknowledgement email, or a bookmark, whose
  signed token has since expired — is now taken to that request in the admin instead of a permissions
  error. The file itself still requires a freshly signed link.

= 0.6.1 =
Superseded by 0.7.0, which contains these fixes. Never released separately.

Two links in the requests screen were built with a helper that HTML-escapes the URL it returns. That
is correct when printing a link into a page, and wrong for a URL handed to the admin app as data and
set straight onto a button: the browser then sent `amp;request` instead of `request`, so the server
never saw the parameters and refused. Both are fixed, and both are now covered by tests that fail
against the old form. These are the only two places where the plugin builds a URL server-side and
hands it to JavaScript.

* Fix: **Export CSV** failed with "The link you followed has expired". The export nonce never reached
  the server. Present since 0.3.0.
* Fix: opening a durable-medium **receipt** from the request detail panel failed with "You are not
  authorized to perform this action". Present since 0.5.1, and it affected every receipt opened from
  that panel, whatever the age of the withdrawal. The Download link in the no-JavaScript requests
  table was never affected by either bug.
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

= 0.8.0 =
The order lookup screen's four texts and the withdrawal page's button colour are now settings, and the buttons can inherit your theme's style instead. Fixes two options left behind on uninstall. Nothing changes until you change it. No data migration required.

= 0.7.0 =
Customers can follow their withdrawal requests, and the receipt PDF, from My Account. Fixes a
withdrawal page that was deleted or unpublished silently sending every withdrawal link to your home
page. Recommended for all sites. No data migration required.

= 0.6.1 =
Fixes two broken links in the requests screen: Export CSV reporting an expired link, and the
durable-medium receipt refusing to open from the request detail panel. Both affected every use, not
just occasional ones. Recommended for all sites. No data migration required.

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
