#!/usr/bin/env bash
# Activation smoke test: what the WordPress.org reviewer does by hand.
#
#   tests/Smoke/activation-smoke.sh <plugin-dir>
#
# <plugin-dir> is the plugin in distribution shape (.distignore applied, production
# vendor/). It is copied into a fresh WordPress with WP_DEBUG on, activated from
# plugins.php as the administrator, and every admin screen is opened. The run fails on
# any PHP warning/notice/deprecation, activation output, missing table, plugin asset
# on a screen that is not ours, or external HTTP request. Last, with Polylang active, a
# translation scheduled as an editor does must finish in the background and start its
# workflow (OpenAI stubbed in smoke-observer.php). See inside-container.sh.
#
# Environment:
#   WP_CORE_VERSION   WordPress version (default: current release)
#   SMOKE_PHP         PHP version of the wordpress:cli image (default: 8.2)
#   SMOKE_KEEP        non-empty: leave the WordPress tree behind for inspection
#   SMOKE_DB          mysql (default) or sqlite: the SQLite Database Integration drop-in,
#                     the same SQL translation layer WordPress Playground runs on
#   SMOKE_UNREACHABLE_HOME  non-empty: the site's public name points, inside the
#                     container, at an address nothing answers on (a site behind a
#                     gateway, like trans.info); background work must still run
#   SMOKE_LOOPBACK_URL  sets TREETANK_TRANS_LOOPBACK_URL, e.g. http://127.0.0.1:8080
#
# Everything is staged under /tmp, never in the checkout: the container runs as root.
set -euo pipefail

PLUGIN_DIR="$(cd "${1:?usage: $0 <plugin-dir>}" && pwd)"
TOOLS="$(cd "$(dirname "$0")" && pwd)"
ID="${CI_JOB_ID:-$$}"
WORK="${TMPDIR:-/tmp}/treetank-trans-activation-${ID}"
NET="pt-act-${ID}"
DB="pt-act-db-${ID}"
IMAGE="wordpress:cli-php${SMOKE_PHP:-8.2}"
SMOKE_DB="${SMOKE_DB:-mysql}"
# Pinned: the drop-in is what turns our MySQL into SQLite, so a new release is a new test.
SQLITE_PLUGIN_VERSION="3.1.3"
# Pinned, for the background translation check: 3.7.3 is what trans.info runs; it needs
# WordPress 6.2, so the 6.0 job gets the last release that supports it.
case "${WP_CORE_VERSION:-}" in
    5.*|6.0*|6.1*) POLYLANG_VERSION="3.5.4" ;;
    *)             POLYLANG_VERSION="3.7.3" ;;
esac

cleanup() {
    docker rm -fv "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    if [ -z "${SMOKE_KEEP:-}" ]; then
        # Root-owned files: remove them from inside a container, as the CI jobs do.
        docker run --rm --user root --entrypoint sh -v "$WORK:/cleanup" "$IMAGE" \
            -c 'rm -rf /cleanup/* /cleanup/.[!.]* /cleanup/..?*' >/dev/null 2>&1 || true
        rmdir "$WORK" 2>/dev/null || true
    else
        echo "WordPress tree kept in $WORK/site"
    fi
}
trap cleanup EXIT

mkdir -p "$WORK/site/wp-content/plugins/treetank-trans" "$WORK/tools"
rsync -a "$PLUGIN_DIR/" "$WORK/site/wp-content/plugins/treetank-trans/"
cp "$TOOLS/crawl.php" "$TOOLS/smoke-lib.php" "$TOOLS/smoke-observer.php" "$TOOLS/inside-container.sh" \
    "$TOOLS/seed-prompts.php" "$TOOLS/prompt-fixture.txt" \
    "$TOOLS/seed-translation.php" "$TOOLS/translate.php" "$WORK/tools/"
# Unpacked but inactive: the activation and crawl run on a site with only our plugin;
# inside-container.sh activates Polylang for the background translation afterwards.
curl -fsSL -o "$WORK/tools/polylang.zip" "https://downloads.wordpress.org/plugin/polylang.${POLYLANG_VERSION}.zip"
unzip -q "$WORK/tools/polylang.zip" -d "$WORK/site/wp-content/plugins/"

docker network create "$NET" >/dev/null
case "$SMOKE_DB" in
mysql)
    docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=pcp -e MYSQL_DATABASE=wp mysql:5.7 >/dev/null
    for _ in $(seq 1 40); do
        docker exec "$DB" mysqladmin ping -uroot -ppcp --silent >/dev/null 2>&1 && break
        sleep 2
    done
    # mysqladmin answers before the server accepts TCP clients.
    sleep 3
    ;;
sqlite)
    curl -fsSL -o "$WORK/tools/sqlite-database-integration.zip" \
        "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip"
    unzip -q "$WORK/tools/sqlite-database-integration.zip" -d "$WORK/site/wp-content/plugins/"
    ;;
*)
    echo "SMOKE_DB must be mysql or sqlite, got: $SMOKE_DB" >&2; exit 2
    ;;
esac

# 10.255.255.1 is a non-routed address: connections to it hang until the timeout,
# which is what a gateway-only host name looks like from inside a container.
docker run --rm --network "$NET" --user root \
    --add-host smoke-public.test:10.255.255.1 \
    -e DB_HOST="$DB" -e SMOKE_DB="$SMOKE_DB" -e WP_CORE_VERSION="${WP_CORE_VERSION:-}" \
    -e POLYLANG_VERSION="$POLYLANG_VERSION" -e SMOKE_UNREACHABLE_HOME="${SMOKE_UNREACHABLE_HOME:-}" -e SMOKE_LOOPBACK_URL="${SMOKE_LOOPBACK_URL:-}" \
    -v "$WORK/site:/var/www/html" -v "$WORK/tools:/smoke-tools:ro" \
    --entrypoint sh "$IMAGE" /smoke-tools/inside-container.sh
