# Releasing to WordPress.org

The plugin is published at
[wordpress.org/plugins/erred-eu-order-withdrawal-for-woocommerce](https://wordpress.org/plugins/erred-eu-order-withdrawal-for-woocommerce/).

SVN repository: `https://plugins.svn.wordpress.org/erred-eu-order-withdrawal-for-woocommerce`

```
trunk/    the current release (wp.org serves the version in readme.txt's Stable tag)
tags/     one immutable directory per released version
assets/   banner, icon and screenshots — NOT shipped to users, not part of the plugin
```

There is no automated deploy. Releases are done by hand, deliberately: a wp.org release cannot be
withdrawn, only superseded by another.

---

## 1. Before you touch SVN

Run everything in [`ROADMAP.md`](ROADMAP.md) → "Before every release". In particular the version must
be identical in all four places, or wp.org will serve something other than what you built:

| Where | Field |
|---|---|
| `recesso-digitale.php` | `Version:` header |
| `recesso-digitale.php` | `RECESSO_DIG_VERSION` constant |
| `readme.txt` | `Stable tag:` |
| `package.json` | `version` |

```sh
grep -n "Version:\|RECESSO_DIG_VERSION" recesso-digitale.php
grep -n "^Stable tag:" readme.txt
grep -n '"version"' package.json
```

`readme.txt` also needs a `== Changelog ==` entry and an `== Upgrade Notice ==` entry for the new
version. **The upgrade notice must be under 300 characters** — Plugin Check warns above that, and the
notice is what users see in their update screen.

Build the distribution and check it:

```sh
bash bin/build-dist.sh          # → dist/erred-eu-order-withdrawal-for-woocommerce.zip
```

Then run Plugin Check **against the built zip**, never the working directory — the dev folder is named
`wc-reso-ordini`, which produces text-domain and trademarked-slug errors that do not exist in the
distribution:

```sh
unzip -q dist/erred-eu-order-withdrawal-for-woocommerce.zip -d /tmp/pc
cp -R /tmp/pc/erred-eu-order-withdrawal-for-woocommerce .wp-env/uploads/pc-plugin
npx wp-env run cli bash -c \
  "cp -R /var/www/html/wp-content/uploads/pc-plugin \
   /var/www/html/wp-content/plugins/erred-eu-order-withdrawal-for-woocommerce"
npx wp-env run cli wp plugin check erred-eu-order-withdrawal-for-woocommerce
```

Expect **"Success: Checks complete. No errors found."** Clean up the copy afterwards.

Finally, install that same zip into a clean site and activate it. A build that passes every test can
still fail on activation — check the version constant, the schema version and, after a schema change,
that the new column actually landed.

---

## 2. What ships

`bin/build-dist.sh` carries its **own** rsync exclude list; `.distignore` mirrors it for tooling that
reads that file. Excluding something means editing both — the script is what actually builds the zip.

What ends up in it:

- `recesso-digitale.php`, `uninstall.php`, `readme.txt`, `wpml-config.xml`
- `src/`, `templates/`, `languages/`
- `build/` — compiled assets — **and** `assets/`, `package.json`, `package-lock.json`

`docs/` is excluded: this file and the roadmap are for maintainers, and there is no reason to copy
them onto every user's server.

That last point is not optional. wp.org guideline #4 requires the human-readable source of every
compiled bundle to ship with it. A previous release stripped `assets/` and was **pended at review**
for exactly this. Do not add them back to `.distignore`.

`vendor/` is installed with `--no-dev` from `composer.lock`, so the build is reproducible.

---

## 3. Publishing

You need a wordpress.org account with commit access to this plugin. SVN will prompt for it.

```sh
VERSION=0.6.0
SLUG=erred-eu-order-withdrawal-for-woocommerce
SVN=https://plugins.svn.wordpress.org/$SLUG

# Check out trunk, tags and assets only — a full checkout pulls every past tag.
svn checkout --depth immediates $SVN /tmp/$SLUG-svn
cd /tmp/$SLUG-svn
svn update --set-depth infinity trunk
svn update --set-depth infinity assets

# Replace trunk with the built plugin.
unzip -q /path/to/dist/$SLUG.zip -d /tmp/unpacked
rsync -a --delete /tmp/unpacked/$SLUG/ trunk/

# Stage adds and deletes (svn does not notice them by itself).
svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn delete

svn status                       # read this properly before committing
svn commit -m "Release $VERSION"
```

Then tag. **Copy trunk, do not re-upload the files** — a tag is a snapshot of what you just shipped,
and copying is what makes it provably identical:

```sh
svn copy $SVN/trunk $SVN/tags/$VERSION -m "Tag $VERSION"
```

wp.org picks the release up within a few minutes. It serves whatever `Stable tag:` in **trunk's**
`readme.txt` names, so the tag directory and the stable tag must agree.

---

## 4. Assets

`assets/` holds the banner, icon and screenshots shown on the plugin page. They are versioned in SVN
but never shipped to users, and they are **not** built by `bin/build-dist.sh`.

Live files: `banner-1544x500.png`, `banner-772x250.png`, `icon.svg`, `screenshot-1..5.jpg`.

Screenshot captions come from `== Screenshots ==` in `readme.txt`; the numbering must match. The
repo's `.wordpress-org/` holds PNG sources for screenshots 1–4 — regenerate them with
`npm run screenshots` (a Playwright run tagged `@screenshot`) when the UI changes, then convert and
commit to SVN separately from a release. Changing assets does not require a version bump.

---

## 5. After releasing

- Tag the release in git and push: `git tag v$VERSION && git push origin v$VERSION`.
- Check the plugin page renders the new changelog, and that the update appears on a real site.
- Watch the support forum for the first few days — a schema migration is the thing most likely to
  surface on a host configuration we have not seen.

---

## If a release is broken

You cannot delete a release. Fix forward: bump the patch version, ship the fix, and leave the broken
tag in place. If the problem is severe, point `Stable tag:` in trunk back at the previous good version
and commit trunk alone — wp.org will serve that older version immediately, without touching any tag.
