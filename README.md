# ErreD EU Order Withdrawal for WooCommerce

Online withdrawal function (`«recedere dal contratto qui»`) for WooCommerce, implementing
**art. 54-bis** of the Italian *Codice del Consumo* (introduced by D.Lgs. 209/2025, transposing
EU Directive 2023/2673), applicable to distance contracts concluded online from **19 June 2026**.

> The plugin does **not** create a right of withdrawal. It provides the online channel to
> exercise an existing one, and honours the legal exceptions (art. 59).

## Requirements

| Component   | Minimum |
|-------------|---------|
| PHP         | 8.2     |
| WordPress   | 6.7     |
| WooCommerce | 8.2 (HPOS required) |

## Development

```bash
composer install      # PHP deps + dev tooling (PHPCS, PHPStan, PHPUnit)
npm install           # JS build tooling (@wordpress/scripts, wp-env)

composer run lint     # PHPCS (WordPress Coding Standards + PHPCompatibility)
composer run analyze  # PHPStan level 8
composer run test     # PHPUnit unit suite (Domain/, WordPress-free)
npm run build         # Compile assets to build/

npx wp-env start      # Boot WordPress + WooCommerce locally (Docker)

bash bin/build-dist.sh # Build installable zip in dist folder
```

### Integration tests (must run inside wp-env)

The integration suite boots a real WordPress + WooCommerce via `wp-load.php`, so it **must run
inside the wp-env `tests-cli` container**, not directly on the host. Running `composer
test:integration` locally is expected to print
`Cannot locate wp-load.php at /var/www/html/wp-load.php` — that is the environment guard, not a
test failure.

```bash
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wc-reso-ordini \
  -- php vendor/bin/phpunit -c phpunit-integration.xml.dist
```

### Releasing

Submit **only** the regenerated `dist/erred-eu-order-withdrawal-for-woocommerce.zip` from
`bash bin/build-dist.sh` — never the source directory. Before submitting, run the official Plugin
Check against that exact zip (extract it under its real `erred-eu-order-withdrawal-for-woocommerce`
slug inside wp-env, then `wp plugin check erred-eu-order-withdrawal-for-woocommerce`).

## Build state

See [`PROGRESS.md`](PROGRESS.md) for the live build checklist. The engineering guide and rules
live in the maintainer's local engineering guide (kept outside the repository).

## License

GPL-2.0-or-later.
