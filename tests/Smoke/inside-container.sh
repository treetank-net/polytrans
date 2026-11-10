#!/bin/sh
# Runs inside the wordpress:cli container; started by activation-smoke.sh.
# Expects: the plugin at wp-content/plugins/treetank-trans, these tools in /smoke-tools,
# DB_HOST and optionally WP_CORE_VERSION in the environment.
set -eu

cd /var/www/html
WP="php -d memory_limit=1G /usr/local/bin/wp --allow-root"
SMOKE=/var/www/html/smoke
PREFIX=smk_
FAIL=0

$WP core download ${WP_CORE_VERSION:+--version=$WP_CORE_VERSION} --force --quiet
# A non-default table prefix: a table created without $wpdb->prefix shows up missing.
$WP config create --dbname=wp --dbuser=root --dbpass=pcp --dbhost="$DB_HOST" --dbprefix="$PREFIX" --skip-check --force --quiet
$WP config set WP_DEBUG true --raw --quiet
$WP config set WP_DEBUG_LOG "$SMOKE/debug.log" --quiet
$WP config set WP_DEBUG_DISPLAY true --raw --quiet
$WP config set DISABLE_WP_CRON true --raw --quiet
if [ "${SMOKE_DB:-mysql}" = sqlite ]; then
    # The drop-in WordPress Playground uses (activation-smoke.sh unpacked the plugin).
    sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PWD/wp-content/plugins/sqlite-database-integration#" \
        -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
        wp-content/plugins/sqlite-database-integration/db.copy > wp-content/db.php
fi
$WP core install --url=http://localhost:8080 --title=smoke --admin_user=a --admin_password=a --admin_email=a@example.test --skip-email --quiet

mkdir -p "$SMOKE" wp-content/mu-plugins
cp /smoke-tools/smoke-observer.php wp-content/mu-plugins/
: > "$SMOKE/debug.log"

echo "WordPress $($WP core version) · PHP $(php -r 'echo PHP_VERSION;') · ${SMOKE_DB:-mysql} · table prefix $PREFIX"
echo "Plugin $($WP plugin get treetank-trans --field=version) — inactive, about to be activated"

PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=512M -S 0.0.0.0:8080 -t /var/www/html > "$SMOKE/server.log" 2>&1 &
SERVER=$!
for _ in $(seq 1 20); do
    php -r 'exit(@file_get_contents("http://localhost:8080/wp-login.php") === false ? 1 : 0);' && break
    sleep 1
done

php /smoke-tools/crawl.php http://localhost:8080 "$SMOKE" || FAIL=1

# Deactivate and reactivate: activation on a site that already has our options.
echo "Reactivation"
$WP plugin deactivate treetank-trans --quiet || FAIL=1
$WP plugin activate treetank-trans --quiet || FAIL=1
kill "$SERVER" 2>/dev/null || true

echo "Tables (dbDelta, \$wpdb->prefix)"
# Through $wpdb, not `wp db query`: the image's MariaDB client refuses MySQL 5.7's TLS.
TABLES=$($WP eval 'global $wpdb; echo implode("\n", $wpdb->get_col("SHOW TABLES LIKE \"%polytrans%\""));')
for table in polytrans_logs polytrans_workflows polytrans_assistants polytrans_usage polytrans_refinement_runs polytrans_translation_runs; do
    if printf '%s\n' "$TABLES" | grep -qx "${PREFIX}${table}"; then
        echo "  ✓ ${PREFIX}${table}"
    else
        echo "  ✗ ${PREFIX}${table} missing"; FAIL=1
    fi
done
STRAY=$(printf '%s\n' "$TABLES" | grep -v "^${PREFIX}" | grep . || true)
if [ -n "$STRAY" ]; then
    echo "  ✗ tables outside \$wpdb->prefix: $STRAY"; FAIL=1
fi

echo "External requests (Guideline 7)"
if [ -s "$SMOKE/external-http.log" ]; then
    echo "  ✗ the plugin contacted external servers without being configured to:"
    sed 's/^/    /' "$SMOKE/external-http.log" | head -40
    FAIL=1
else
    echo "  ✓ none"
fi

echo "debug.log (WP_DEBUG)"
# PHP errors, failed queries (wpdb logs every one; on SQLite also the ones the MySQL
# translation layer cannot handle), plus our own report of markup wp_kses() removed from a template. Only what
# was logged after activation, minus what core alone logged before it (crawl.php writes
# the marker): a fresh core on its supported PHP logs nothing, an old one plenty.
PATTERN="PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|Uncaught|called <strong>incorrectly</strong>|is <strong>deprecated</strong>|wp_kses stripped markup|WordPress database error"
normalize() { grep -E "$PATTERN" | sed 's/^\[[^]]*\] //' | sort -u; }
sed '/=== TREETANK SMOKE: ACTIVATION ===/,$d' "$SMOKE/debug.log" | normalize > "$SMOKE/debug-baseline.log"
sed -n '/=== TREETANK SMOKE: ACTIVATION ===/,$p' "$SMOKE/debug.log" | normalize > "$SMOKE/debug-after.log"
comm -13 "$SMOKE/debug-baseline.log" "$SMOKE/debug-after.log" > "$SMOKE/debug-errors.log"
if [ -s "$SMOKE/debug-errors.log" ]; then
    echo "  ✗ $(wc -l < "$SMOKE/debug-errors.log") distinct message(s) after activation:"
    sed 's/^/    /' "$SMOKE/debug-errors.log" | head -40
    FAIL=1
else
    echo "  ✓ nothing new after activation ($(wc -l < "$SMOKE/debug-baseline.log") distinct baseline message(s) from core)"
fi

if [ "$FAIL" -ne 0 ]; then
    echo; echo "❌ Activation smoke test failed"; exit 1
fi
echo; echo "✅ Activation smoke test passed"
