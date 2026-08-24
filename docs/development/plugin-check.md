# Running the checks a WordPress.org reviewer runs

Two independent gates, both wired into CI (`phpcs` and `plugin-check` jobs in
`.gitlab-ci.yml`). This page is how to run them by hand while fixing something.

## PHPCS

```bash
docker compose -f docker-compose.test.yml run --rm polytrans-test \
  php -d memory_limit=1G vendor/bin/phpcs --standard=phpcs.xml --report=full --no-colors
```

Errors only (what CI blocks on):

```bash
docker compose -f docker-compose.test.yml run --rm polytrans-test \
  php -d memory_limit=1G vendor/bin/phpcs --standard=phpcs.xml --report=source --no-colors --warning-severity=0
```

Run it in the container, not on the host: the host PHP is missing `xmlwriter`/`SimpleXML`
and PHPCS refuses to start without them.

These extensions are requirements of the development gates, not of the PolyTrans
runtime. `dom`, `xml`, `xmlwriter`, `SimpleXML` and `xmlreader` are pulled in by
PHPUnit, PHPCS and WordPress Coding Standards. The test `Dockerfile` installs them
for that purpose. A production `composer install --no-dev` for this plugin has no
such platform requirement, and the release ZIP does not bundle PHP extensions;
the site's normal PHP/WordPress requirements remain the ones declared in
`polytrans.php` and `readme.txt`.

**Why the ruleset looks the way it does.** It carries no formatting or indentation rules.
The earlier one referenced `WordPress-Core` wholesale, which mandates tabs while this
codebase uses spaces — 35 251 of 35 661 findings were "tabs must be used", which is not a
gate anyone reads. Worse, `wp-coding-standards/wpcs` 2.3 crashes on PHP 8.3
(`PrefixAllGlobalsSniff.php:280`, `trim(): Passing null`), and PHPCS reports that as
*"An error occurred during processing; checking has been aborted"* — per file, for all 121
of them. So the old profile also silently stopped checking. WPCS is pinned to `^3.1` for
that reason; do not lower it.

## Plugin Check

### The short way: `./dev.sh smoke`

```bash
./dev.sh smoke        # current WordPress release — what the reviewer runs
./dev.sh smoke 6.8    # a specific version
```

It stages the plugin in distribution shape (`.distignore` applied), installs WordPress and
Plugin Check in Docker, activates the plugin and reports the error/warning counts. Use this
before every submission and before every release build: `composer test` runs without
WordPress, so it cannot see an activation fatal, a missing table, a broken admin template,
or `outdated_tested_upto_header` — which turns into an ERROR the day WordPress ships a
release. The WordPress tree is left behind for inspection and the path is printed; it lives
outside the checkout, so a root container cannot leave files the next checkout can't remove.

Verified on 2026-08-24 against WordPress 7.1 with Plugin Check 2.1.0: **0 errors, 53
warnings**, all in the families listed at the bottom of this page — 33 `AIProvider`, 12
`DirectDatabaseQuery`, 5 `SlowDBQuery`, 1 `PostNotIn`, 1 `SchemaChange`, 1 `error_log`
(`Bootstrap.php`). The count came down from 56 when the activation-time diagnostic that
queried the posts table and wrote test post meta was removed.

### The long way, step by step

The official tool, requiring a WordPress install and a database.

```bash
# 1. database
docker run -d --name pt-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp mysql:5.7

# 2. WordPress + Plugin Check, into a throwaway directory
WP=$(mktemp -d) && chmod 777 "$WP"
docker run --rm --link pt-mysql:mysql -v "$WP":/var/www/html -w /var/www/html --user root \
  wordpress:cli-php8.2 sh -c '
    php -d memory_limit=1G /usr/local/bin/wp core download --version=6.8 --allow-root &&
    php -d memory_limit=1G /usr/local/bin/wp config create --allow-root --dbname=wp --dbuser=root --dbpass=root --dbhost=mysql --skip-check &&
    php -d memory_limit=1G /usr/local/bin/wp core install --allow-root --url=http://example.test --title=T --admin_user=a --admin_password=a --admin_email=a@b.co --skip-email &&
    php -d memory_limit=1G /usr/local/bin/wp plugin install plugin-check --version=2.0.0 --allow-root --activate'

# 3. the plugin, exactly as it ships
docker run --rm -v "$WP":/wp --user root alpine chmod -R 777 /wp/wp-content
rsync -a --exclude-from=.distignore ./ "$WP/wp-content/plugins/polytrans/"

# 4. the scan
docker run --rm --link pt-mysql:mysql -v "$WP":/var/www/html -w /var/www/html --user root \
  wordpress:cli-php8.2 php -d memory_limit=2G /usr/local/bin/wp plugin check polytrans \
  --allow-root --exclude-directories=vendor,node_modules --format=csv

# 5. cleanup
docker rm -f pt-mysql
```

Errors only, with the file each belongs to:

```bash
awk '/^FILE:/{f=$2} /,ERROR,/{print f" :: "$0}' report.csv
```

**Always copy through `.distignore`.** Scanning the working tree directly hides the
packaging failures — a `hidden_files` error for `.codex`, a dangling `AGENTS.md` symlink —
that only appear in what actually ships.

### PHPCS annotations and Plugin Check

Plugin Check runs PHP_CodeSniffer with its own standards. A `phpcs:ignore` is honored,
but only when both the sniff code and the target line are correct. For example, the
direct-database sniff uses:

```php
// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name is built from $wpdb->prefix and values are prepared.
$rows = $wpdb->get_results($query, ARRAY_A);
```

`WordPress.DB.PreparedSQL.*` and `PluginCheck.Security.DirectDB.*` are different sniff
families. Also, an annotation on an inner `prepare()` line does not suppress a finding
attached to the outer `$wpdb->get_*()` call. When several codes apply to one statement,
put them on one annotation immediately before that statement.

**Plugin Check's ruleset is fixed, and this is why the sanitiser annotations are still
there.** Its PHPCS runs come from `plugin-check/phpcs-rulesets/plugin-check.ruleset.xml`;
there is no hook for a plugin to contribute sniff *properties*, so the
`customSanitizingFunctions` entry in our `phpcs.xml` — which makes
`PolyTrans\Core\sanitize_prompt_template()` and `sanitize_input_deep()` count as real
sanitisation — reaches our own runs and nothing else. Inline annotations are the only thing
both runs honour.

So prompt and workflow input **is** sanitised, and the line above each call still carries a
`ValidatedSanitizedInput.InputNotSanitized` annotation. Two things follow. Removing those
annotations does not make the package cleaner: it turns 0 findings into 56 warnings in the
report a reviewer generates, in the exact section that was flagged. And the annotation must
keep naming the sanitiser on the line below it — an annotation whose stated reason is
"trusted admin input" is what the March and August rounds both rejected, because capability
and nonce checks decide *who* may post, never *what* arrives.

## Two traps worth knowing

**A `phpcs:ignore` covers exactly one line.** Over a statement spanning several lines it
suppresses nothing, and the violations get reported against the inner lines. That is how
seven escaping errors survived in `UsageMetaBox.php` with an annotation sitting right above
them. If a statement is multi-line, assign it to a variable first and annotate the
one-line statement.

**The sniff code in the annotation has to be the one that fires.** `PreparedSQL.NotPrepared`
and `PreparedSQL.InterpolatedNotPrepared` are different sniffs; naming the wrong one is the
same as writing no annotation at all.

## Warnings that are expected

* `WordPress.DB.DirectDatabaseQuery.*` — the plugin owns four custom tables
  (`polytrans_logs`, `polytrans_workflows`, `polytrans_assistants`, `polytrans_usage`), and
  `WP_Query` does not reach custom tables.
* `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` on table names — a table name cannot
  be a prepared value; it is built from `$wpdb->prefix`, never from a request.
* `PluginCheck.Security.DirectDB.UnescapedDBParameter` should be treated as a code
  review trigger, not as a baseline warning. The current package has zero of these:
  trusted table/SQL fragments are annotated with the exact sniff, while request-derived
  fragments must be prepared or rejected.
* `WordPress.Security.NonceVerification.Recommended` on read-only admin query parameters
  used while deciding whether to enqueue UI assets.
* `WordPress.PHP.DevelopmentFunctions.error_log_error_log` — exactly one, in
  `Core\Diagnostics`, the plugin's only route to the PHP error log. It is silent unless
  `WP_DEBUG` is on. A second one appearing anywhere else is a regression, and
  `tests/Architecture/NamingConventionsTest.php` fails on it.
* `WordPress.DB.SlowDBQuery.*` and `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_*`
  on search/report queries whose performance trade-off is intentional.
* `WordPress.DB.DirectDatabaseQuery.SchemaChange` for lazy plugin-table migrations.
* `Squiz.PHP.DiscouragedFunctions.Discouraged` for the best-effort time limit used by the
  long-running async worker.
* `WordPress.WP.AlternativeFunctions.json_encode_json_encode` where the result is sent to a
  provider API rather than to the browser.
* `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` — appeared with the Plugin Check
  release that shipped alongside WordPress 7.0, and fires on every provider base URL
  (`api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`, plus
  `openrouter.ai` in `Core\ModelPricing`). It is a recommendation to move to the core
  AI Client (`wp_ai_client_prompt()`). We do not: the plugin declares
  `Requires at least: 6.0`, and the features depend on per-call parameters the core client
  does not expose — reasoning effort, provider-side assistants and managed prompts, and the
  raw `usage` block that `Core\UsageRecorder` prices. Adding it as an *additional* provider
  is the plan once the minimum supported WordPress version can be raised.

## `Tested up to` is a moving ERROR

`outdated_tested_upto_header` is an **ERROR**, not a warning, and it re-appears on its own
every time WordPress ships a release — the value has to equal the current version, so a
package that passed last month fails today with no code change. Check it right before every
submission and before every release build; 7.0 became an error the moment 7.1 shipped.
