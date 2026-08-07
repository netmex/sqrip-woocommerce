# Working on sqrip-woocommerce

House rules for this repository. They exist because each one was learned the hard
way; the note in brackets says what went wrong.

---

## Hard rules — never break these

**Never `git add -A` or `git add .` in this repository.**
`CAMT/` holds Markus' **real bank statements** — IBANs, payer names and addresses of
every customer of a shop. The repository is public. Stage files by name.
`.distignore` and `.gitignore` both exclude it, but a blanket `add` still catches
anything newly untracked. *(Once committed 25 real statements, 194'000 lines. Caught
before the push.)*
The fixtures under `tests/camt/fixtures/` are synthetic (dummy IBAN `CH000…`) and
belong in version control.

**Never change the order-status logic.**
sqrip's selling point is that every shop defines its own order statuses. Do not
restrict the status choices, do not add warnings for particular statuses, do not move
where a status is set. "Pending payment" as the sqrip status is a legitimate customer
choice, even though WooCommerce then sends no e-mails.

**Never change what existing shops have configured.**
Customers have their settings in place. A new version must not alter them. Every new
feature ships **off**, and `sqrip_get_plugin_option()` deliberately ignores form-field
defaults — so a shop that updates and changes nothing keeps exactly the behaviour it
had. Preserve that property.

**Never require customers to touch server files** to fix something.

---

## Markus works in this directory at the same time

Check before doing anything: `git rev-parse --abbrev-ref HEAD`. The checkout jumps
between branches without warning.

For any work of your own, take a separate copy instead of switching his branch:

```bash
git worktree add /tmp/wt-<name> <branch>
```

Ask before committing to a branch he is actively building on. *(Branch collisions and
a near loss of work before this became the rule.)*

---

## Building the ZIP

Markus tests from a ZIP, then gives the word to release. Convention:

- **Folder:** `/Users/netmex/Developer/ZIP/`
- **Name:** `YYYYMMDD-HHMM sqrip <version>.ZIP` — capital `.ZIP`, spaces as shown
  Examples: `20260802-1033 sqrip 1.10.4.ZIP`, `20260802-1053 sqrip 1.11.0-beta14.ZIP`
- **Inside:** one folder `sqrip-swiss-qr-invoice/` — the folder name must equal the
  wp.org slug, otherwise the shop ends up with a second copy alongside the installed one

Build it from a **commit**, not from the working copy, so the ZIP matches what will be
released:

```bash
mkdir -p /tmp/zipbuild/sqrip-swiss-qr-invoice
git archive HEAD | tar -x -C /tmp/zipbuild/sqrip-swiss-qr-invoice
cd /tmp/zipbuild/sqrip-swiss-qr-invoice
rm -rf assets .github docs tests CAMT .claude scratchpad \
       .distignore .gitignore composer.json composer.lock \
       WORDPRESS-ORG-TRANSLATION-IMPORT.md
cd .. && zip -qr "/Users/netmex/Developer/ZIP/$(date '+%Y%m%d-%H%M') sqrip <version>.ZIP" \
       sqrip-swiss-qr-invoice -x '*.DS_Store'
```

The `rm -rf` list mirrors `.distignore` — keep the two in step.

**`vendor/` stays in, where it exists.** It holds `chillerlan/php-qrcode`, a runtime
dependency for rendering GiroCodes, so it must not be deleted from the package. As of
August 2026 it is only on the GiroCode branch, not yet on `main` or `release/1.11` —
check with `git ls-tree -r --name-only HEAD -- vendor` before assuming either way.
`composer.json` and `composer.lock` do **not** ship; they are only needed to rebuild
`vendor/`.

Afterwards, check the package actually is what you think:

```bash
unzip -l "<zip>" | grep -icE 'camt|\.xml|tests/'
```

Hits on `class-sqrip-camt-*.php` are fine — those are plugin classes. Any `.xml` or
`CAMT/` entry is not.

---

## Bumping the version

Change **all** of these, or the release is broken:

1. `sqrip-woocommerce.php` line 7 — `* Version:`
2. **Every `wp_enqueue_*` version stamp** in `sqrip-woocommerce.php` (currently 7–9 of
   them). Miss these and browsers keep serving the **cached** old JS and CSS, so the fix
   never reaches the shop that needs it. *(Nearly shipped that way in 1.10.2.)*
3. `README.txt` — `Stable tag:` must equal the git tag exactly
4. `README.txt` — a `== Changelog ==` entry and an `== Upgrade Notice ==` entry

```bash
sed -i '' "s/'1\.10\.3'/'1.10.4'/g; s/^ \* Version:                 1\.10\.3/ * Version:                 1.10.4/" sqrip-woocommerce.php
sed -i '' "s/^Stable tag: 1\.10\.3/Stable tag: 1.10.4/" README.txt
```

Write the changelog for **shop owners**, not for developers: what changes for them,
and whether they have to do anything.

---

## Releasing

Wait for Markus' explicit word. He tests the ZIP first and then says "push und Tag".

1. Push the branch, open a PR against `main`, merge it
2. Tag **exactly** the version — `1.10.4`, never `v1.10.4`. The SVN release folder is
   derived from the tag and must match `Stable tag`. (Older tags in the history are
   inconsistent: `V1.8`, `v1.7`, `1.3`.)
3. Pushing the tag triggers `.github/workflows/main.yml` → 10up deploy to wp.org →
   a GitHub release is created automatically
4. **Verify the delivered package, not the workflow status:**

```bash
curl -sL -o live.zip "https://downloads.wordpress.org/plugin/sqrip-swiss-qr-invoice.<version>.zip"
unzip -qo live.zip && sed -n '7p' sqrip-swiss-qr-invoice/sqrip-woocommerce.php
```

The wp.org **plugin page** lags a few hours behind — that is its cache, not a failed
deploy. The download URL and the SVN tag are the truth.

**Release-candidate pattern:** when `main` is released and a feature branch runs in
parallel, cherry-pick fixes onto a `build/<version>` branch off `main` rather than
collecting them on the feature branch. That way the tested ZIP equals the published
ZIP and no unfinished feature leaks into the release.

---

## After every merge between branches

Git reports conflicts, but it silently produces broken code when two copies of the same
thing sit in **different places** in a file. This has happened twice.

Run all of this, every time:

```bash
for f in $(git diff --cached --name-only --diff-filter=ACM | grep '\.php$'); do php -l "$f"; done
node --check js/sqrip-admin.js
```

Then check for duplicates that no conflict marker warned about:

```bash
grep -c "public function is_available" inc/class-wc-sqrip-payment-gateway.php   # must be 1
```

A duplicated class method is a **fatal error** — the plugin does not start at all, and
you only find out when installing. *(Exactly that survived a "clean" merge once.)*
Also worth checking after a merge: the same guard added twice in
`sqrip-woocommerce.php`, and the same settings row toggled twice in `js/sqrip-admin.js`.

Finally, run the test benches (see below) and check the translations **at runtime**,
not by reading the `.po`.

---

## Translations

Six catalogues in `languages/`: `de_CH`, `de_DE`, `fr_CH`, `fr_FR`, `it_CH`, `it_IT`,
plus the `.pot`. PHP 8.5 and `msgfmt` are installed locally.

- Never build a string by concatenation or interpolation inside `__()`. The msgid then
  never matches at runtime and the text silently stays English. Always
  `sprintf(__('… %s …'), $var)`.
- After changing a `.po`, recompile: `msgfmt -o <file>.mo <file>.po`
- **Verify what actually resolves**, by loading the `.mo` and asking for the exact
  strings the code uses. A `.pot` that contains a string proves nothing — the `.pot`
  once carried translations the `.po` files never received.
- Placeholders must survive: `%s` count in msgid == count in msgstr.

The bundled catalogues are forced over any server language pack by a
`load_textdomain_mofile` filter, with a fallback for locale variants that are not
bundled (e.g. `de_CH_informal`). **When a translation does not take effect, check the
logged-in user's profile locale first** — wp-admin renders in the *user's* language, not
the site language.

---

## Test benches

Not shipped (`/tests` is in `.distignore`). Run with plain `php`:

```bash
php tests/camt/run-tests.php             # camt parser
php tests/camt/run-reconciler-tests.php  # reconciliation
php tests/camt/run-skonto-tests.php      # discount invoice
php tests/camt/run-reminder-tests.php    # payment reminder
php tests/process/run-tests.php          # process overview (1.12)
```

`run-reconciler-tests.php` optionally takes a directory of real bank files as an
argument. Its results must never end up in a commit.

---

## Reporting to Markus

He is not a developer and steers by outcomes.

- Structure: **facts → what follows → what to do**. Say what you measured, not what you
  assume.
- Decide anything you can decide yourself. Do not ask permission for a fix that is
  clearly necessary — but do leave genuine product decisions to him, with a
  recommendation and the counter-argument.
- **Fix only the reported defect.** Before "improving" anything nearby, check the last
  known good version (`git show V1.8.4:<file>`). If it behaved the same way, it is not a
  regression and not your job. *(A well-meant settings-tab rearrangement had to be taken
  back completely.)*
- Do not scale a finding up. Before warning customers, look at what the bug actually
  does in their shop — not at how bad the code looks.
