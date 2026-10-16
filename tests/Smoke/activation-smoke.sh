#!/usr/bin/env bash
# Activation smoke test: what the WordPress.org reviewer does by hand.
#
#   tests/Smoke/activation-smoke.sh <plugin-dir>
#
# <plugin-dir> is the plugin in distribution shape (.distignore applied, production
# vendor/). It is copied into a fresh WordPress with WP_DEBUG on, activated from
# plugins.php as the administrator, and every admin screen is opened. The run fails on
# any PHP warning/notice/deprecation, activation output, missing table, plugin asset
# on a screen that is not ours, or external HTTP request. See inside-container.sh.
#
# Environment:
#   WP_CORE_VERSION   WordPress version (default: current release)
#   SMOKE_PHP         PHP version of the wordpress:cli image (default: 8.2)
#   SMOKE_KEEP        non-empty: leave the WordPress tree behind for inspection
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

cleanup() {
    docker rm -f "$DB" >/dev/null 2>&1 || true
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
cp "$TOOLS/crawl.php" "$TOOLS/smoke-observer.php" "$TOOLS/inside-container.sh" \
    "$TOOLS/seed-prompts.php" "$TOOLS/prompt-fixture.txt" "$WORK/tools/"

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=pcp -e MYSQL_DATABASE=wp mysql:5.7 >/dev/null
for _ in $(seq 1 40); do
    docker exec "$DB" mysqladmin ping -uroot -ppcp --silent >/dev/null 2>&1 && break
    sleep 2
done
# mysqladmin answers before the server accepts TCP clients.
sleep 3

docker run --rm --network "$NET" --user root \
    -e DB_HOST="$DB" -e WP_CORE_VERSION="${WP_CORE_VERSION:-}" \
    -v "$WORK/site:/var/www/html" -v "$WORK/tools:/smoke-tools:ro" \
    --entrypoint sh "$IMAGE" /smoke-tools/inside-container.sh
