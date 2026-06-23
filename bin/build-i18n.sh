#!/usr/bin/env bash
#
# Regenerate the plugin's translation artefacts.
#
# Produces, under languages/ (DOMAIN = the plugin slug / text domain, set below):
#   - <domain>.pot                     (template, scanned from PHP + JS source)
#   - <domain>-it_IT.po / .mo          (Italian catalogue, merged + compiled)
#   - <domain>-it_IT-<hash>.json       (one Jed file per built JS bundle)
#
# The JSON files are what wp_set_script_translations() loads for the React admin and the block
# editor script. WordPress resolves them by hashing the *enqueued* (built) script path, e.g.
# md5("build/admin/index.js"). wp-cli's make-json keys files by the *source* path found in the
# .po (assets/admin/app.js); this script renames each to the built-bundle hash so WordPress finds
# them. The source->bundle mapping below must stay in sync with package.json's build outputs.
#
# Requirements: a running wp-env (provides wp-cli) and local gettext (msgmerge/msgfmt).
#
# Usage: bin/build-i18n.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_CWD="wp-content/plugins/wc-reso-ordini"
DOMAIN="erred-eu-order-withdrawal-for-woocommerce"
LOCALE="it_IT"
LANG_DIR="${ROOT_DIR}/languages"
PO="${LANG_DIR}/${DOMAIN}-${LOCALE}.po"
POT="${LANG_DIR}/${DOMAIN}.pot"

# Built bundles that call wp_set_script_translations (or are registered via block.json), and the
# source file whose extracted strings feed each. Keep aligned with the build:* npm scripts.
declare -a BUNDLE_SOURCES=( "assets/admin/app.js" "assets/frontend/index.js" "assets/frontend/view.js" )
declare -a BUNDLE_BUILT=(   "build/admin/index.js" "build/frontend/index.js" "build/frontend/view.js" )

cd "${ROOT_DIR}"

echo "==> Regenerating POT from PHP + JS source"
npx wp-env run cli --env-cwd="${ENV_CWD}" wp i18n make-pot . "languages/${DOMAIN}.pot" \
	--slug="${DOMAIN}" --domain="${DOMAIN}" --exclude=build,node_modules,vendor,tests,dist

echo "==> Merging existing ${LOCALE} catalogue against the new POT"
msgmerge --update --backup=none --no-fuzzy-matching "${PO}" "${POT}"

UNTRANSLATED="$(msgattrib --untranslated "${PO}" | grep '^msgid ' | grep -vc '^msgid ""$' || true)"
if [ "${UNTRANSLATED}" -gt 0 ]; then
	echo "    WARNING: ${UNTRANSLATED} untranslated string(s) remain in ${PO##*/}; translate before shipping:"
	msgattrib --untranslated "${PO}" | grep '^msgid ' | grep -v '^msgid ""$' | sed 's/^/      /'
fi

echo "==> Compiling ${LOCALE} .mo"
msgfmt --check --statistics "${PO}" -o "${LANG_DIR}/${DOMAIN}-${LOCALE}.mo"

echo "==> Generating per-source JSON (kept, .po left intact)"
npx wp-env run cli --env-cwd="${ENV_CWD}" wp i18n make-json languages --no-purge --pretty-print

echo "==> Renaming JSON from source-path hash to built-bundle hash"
for i in "${!BUNDLE_SOURCES[@]}"; do
	src="${BUNDLE_SOURCES[$i]}"
	built="${BUNDLE_BUILT[$i]}"
	src_hash="$(printf '%s' "${src}" | md5 -q 2>/dev/null || printf '%s' "${src}" | md5sum | cut -d' ' -f1)"
	built_hash="$(printf '%s' "${built}" | md5 -q 2>/dev/null || printf '%s' "${built}" | md5sum | cut -d' ' -f1)"
	src_json="${LANG_DIR}/${DOMAIN}-${LOCALE}-${src_hash}.json"
	built_json="${LANG_DIR}/${DOMAIN}-${LOCALE}-${built_hash}.json"
	if [ -f "${src_json}" ]; then
		mv -f "${src_json}" "${built_json}"
		echo "    ${src} -> ${built}  (${built_hash}.json)"
	else
		echo "    NOTE: no strings extracted for ${src}; skipping"
	fi
done

echo "==> Done. Translation artefacts under languages/:"
ls -1 "${LANG_DIR}"
