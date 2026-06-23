#!/usr/bin/env bash
#
# Build a reproducible, installable distribution zip of the plugin.
#
# Produces dist/recesso-digitale.zip containing a single top-level `recesso-digitale/` folder with
# only the files needed at runtime: compiled assets (build/), production Composer dependencies
# (vendor/, installed with --no-dev), templates, languages and the PHP source. Development files,
# tests, tooling config and local-only developer artefacts are excluded (mirrors .distignore).
#
# Usage: bin/build-dist.sh
#
set -euo pipefail

PLUGIN_SLUG="erred-eu-order-withdrawal-for-woocommerce"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE_DIR="${ROOT_DIR}/.build-tmp"
OUT_DIR="${ROOT_DIR}/dist"
PLUGIN_DIR="${STAGE_DIR}/${PLUGIN_SLUG}"

cd "${ROOT_DIR}"

# Optional local-only exclude list for developer-machine artefacts that are never committed and
# absent from a clean checkout. Keeps machine-specific paths out of both the repo and the zip.
LOCAL_IGNORE="${ROOT_DIR}/.build-ignore.local"
LOCAL_EXCLUDE_ARG=()
if [ -f "${LOCAL_IGNORE}" ]; then
	LOCAL_EXCLUDE_ARG=( --exclude-from="${LOCAL_IGNORE}" )
fi

echo "==> Cleaning staging and output directories"
rm -rf "${STAGE_DIR}" "${OUT_DIR}/${PLUGIN_SLUG}.zip"
mkdir -p "${PLUGIN_DIR}" "${OUT_DIR}"

echo "==> Copying plugin files (excluding development artefacts)"
rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.wp-env' \
	--exclude='.wp-env.json' \
	--exclude='.wp-env.override.json' \
	--exclude='.gitignore' \
	--exclude='.distignore' \
	--exclude='.build-tmp' \
	--exclude='dist' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='tests' \
	--exclude='artifacts' \
	--exclude='.wordpress-org' \
	--exclude='playwright.config.js' \
	--exclude='bin' \
	--exclude='guidelines' \
	--exclude='*.dist' \
	--exclude='.phpunit.result.cache' \
	--exclude='PROGRESS.md' \
	--exclude='PROGRESS-wporg-review.md' \
	--exclude='PROGRESS-recesso-parziale.md' \
	--exclude='PROGRESS-recesso-quantita.md' \
	--exclude='*.local.md' \
	--exclude='.build-ignore.local' \
	--exclude='README.md' \
	--exclude='*.map' \
	--exclude='.DS_Store' \
	${LOCAL_EXCLUDE_ARG[@]+"${LOCAL_EXCLUDE_ARG[@]}"} \
	./ "${PLUGIN_DIR}/"

echo "==> Installing production Composer dependencies (--no-dev, from composer.lock)"
# composer.lock is copied into the stage (not excluded above) so the install resolves the
# exact pinned versions for a reproducible, supply-chain-safe build.
( cd "${PLUGIN_DIR}" && composer install --no-dev --no-interaction --optimize-autoloader --quiet )

# Drop composer.lock (a dev artefact). composer.json is kept on purpose: Plugin Check warns
# when a shipped /vendor directory has no composer.json, and it documents the bundled deps.
rm -f "${PLUGIN_DIR}/composer.lock"

# Reduce the shipped composer.json to a runtime-only manifest: keep what documents the bundled
# vendor/ (name, runtime require, autoload) but strip dev tooling (require-dev, autoload-dev,
# scripts) so no development metadata is distributed.
echo "==> Reducing composer.json to a runtime-only manifest"
php -r '
$f = $argv[1];
$j = json_decode( (string) file_get_contents( $f ), true );
unset( $j["require-dev"], $j["autoload-dev"], $j["scripts"], $j["scripts-descriptions"] );
file_put_contents( $f, json_encode( $j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
' "${PLUGIN_DIR}/composer.json"

echo "==> Hardening generated PHP asset files (direct-access guard)"
# @wordpress/scripts emits build/**/*.asset.php as `<?php return array(...);` with no ABSPATH
# guard. Inject one so Plugin Check does not flag direct file access. Idempotent.
find "${PLUGIN_DIR}/build" -name '*.asset.php' -type f -print0 2>/dev/null | while IFS= read -r -d '' asset; do
	if ! grep -q "ABSPATH" "${asset}"; then
		perl -i -pe "s/^<\\?php /<?php defined( 'ABSPATH' ) || exit;\\n/ if \$. == 1" "${asset}"
	fi
done

echo "==> Creating zip"
( cd "${STAGE_DIR}" && zip -r -q -X "${OUT_DIR}/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}" )

echo "==> Cleaning staging directory"
rm -rf "${STAGE_DIR}"

echo "==> Done: ${OUT_DIR}/${PLUGIN_SLUG}.zip"
