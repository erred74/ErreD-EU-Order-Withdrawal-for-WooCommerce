# PROGRESS — WordPress.org review fixes (target: approval next cycle)

**Current status:** ✅ DONE & verified — ready to rebuild zip and re-upload (v0.5.1)
**Last updated (GMT):** 2026-06-23
**Next action:** re-upload `dist/erred-eu-order-withdrawal-for-woocommerce.zip` to wp.org and reply.

Goal: resolve every item from the WordPress.org review so the plugin is accepted on the next
review. Fixed in code/config (not just explanations). Version bumped to 0.5.1.

## Tasks

### 1. Source-code accessibility (critical)
- [x] `.distignore`: ship `/assets`, `/package.json`, `/package-lock.json`; keep `/bin` excluded
      (Plugin Check forbids `.sh` "application files" — build scripts live in the public repo)
- [x] `bin/build-dist.sh`: matching rsync excludes updated
- [x] `readme.txt`: added `== Source code and build process ==` (source paths, rebuild cmd, repo link)

### 2. Dompdf upgrade 3.0.0 → 3.1.5
- [x] `composer.json` pinned to `3.1.5`
- [x] `composer update dompdf/dompdf --with-all-dependencies` (lock + vendor refreshed)
- [x] `vendor/dompdf/dompdf/VERSION` == 3.1.5 (confirmed in the built zip too)

### 3. Remove `<style>` from PDF template
- [x] `templates/pdf/receipt.php`: `<style>` block converted to inline `style=""` attributes

### 4. Admin receipt download nonce
- [x] `RequestsListTable.php` + `AdminWithdrawalsController.php`: receipt URL wrapped with `wp_nonce_url`
- [x] `ReceiptDownloadController.php`: admin branch requires capability + valid nonce; token branch unchanged

### 5. Product withdrawal-status save hardening
- [x] `WithdrawalStatusFields.php::save_product_field()`: explicit `edit_product` cap +
      `woocommerce_meta_nonce`/`woocommerce_save_data` verification; phpcs:ignore removed
- [x] `phpcs.xml.dist`: registered `edit_product` as a known WooCommerce capability

### 6. Contributors
- [x] `readme.txt`: `Contributors: draison, recessodigitale`

### 7. Version bump → 0.5.1
- [x] `recesso-digitale.php` header `Version:` + `RECESSO_DIG_VERSION`
- [x] `package.json` + `package-lock.json` version
- [x] `readme.txt` `Stable tag` + `== Changelog ==` + `== Upgrade Notice ==` (no AI references)

### 8. Verification
- [x] PHPCS (`composer run lint`) — 0 errors, 0 warnings
- [x] PHPStan L8 (`composer run analyze`) — 0 errors
- [x] Unit tests — 20 passed
- [x] Integration tests (wp-env tests-cli) — 78 passed
- [x] `npm run build` — bundles regenerated from `assets/`
- [x] `bash bin/build-dist.sh` — zip ships assets/, package.json, package-lock.json, dompdf 3.1.5;
      excludes bin/, tests, README.md, PROGRESS*, composer.lock
- [x] Plugin Check on the built zip — **0 errors, 0 warnings**
- [ ] manual smoke (optional): admin + token receipt download, product status save, PDF render

### 9. Docs
- [x] updated `PROGRESS.md` (status/date/next-action)
- [x] noted in the local engineering guide §18 that dist ships assets/ source + package.json + repo link
```

## Items explained (no code change needed, but addressed anyway)
- PDF `<style>` (review item 1): inlined to avoid an automated re-flag.
- Nonces/permissions (review item 2): main flow & REST were already covered; admin receipt
  download and product-status save additionally hardened to be explicit.

## Reply to reviewer (short, per their request)
Source JS/CSS now bundled in the zip (assets/) with build steps and the public repo documented in
the readme; Dompdf updated to 3.1.5; Contributors corrected; admin receipt download and product
status save now use explicit nonce + capability checks; the PDF `<style>` was inlined. Plugin
Check passes with no errors or warnings.
