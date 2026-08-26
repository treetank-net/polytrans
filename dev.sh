#!/bin/bash

# PolyTrans Development Tools
# Docker-based development workflow for consistent environment

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[TreeTank]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[TreeTank]${NC} $1"
}

print_error() {
    echo -e "${RED}[TreeTank]${NC} $1"
}

# Help function
show_help() {
    echo "PolyTrans Development Tools"
    echo ""
    echo "Usage: ./dev.sh [command]"
    echo ""
    echo "Commands:"
    echo "  setup         Build Docker containers and install dependencies"
    echo "  phpcs         Run PHP CodeSniffer (check coding standards)"
    echo "  phpcbf        Run PHP Code Beautifier (fix coding standards)"
    echo "  phpmd         Run PHP Mess Detector"
    echo "  test          Run PHPUnit tests"
    echo "  coverage      Run tests with coverage report"
    echo "  shell         Open interactive shell in development container"
    echo "  clean         Remove Docker containers and images"
    echo "  all           Run all quality checks (phpcs, phpmd, test)"
    echo "  smoke [ver]   Install WordPress (default: current release) in Docker, activate the"
    echo "                distribution-shaped plugin, run Plugin Check and the smoke assertions"
    echo ""
    echo "Examples:"
    echo "  ./dev.sh setup"
    echo "  ./dev.sh phpcs"
    echo "  ./dev.sh test"
    echo "  ./dev.sh smoke        # current WordPress"
    echo "  ./dev.sh smoke 6.8    # a specific version"
}

# Setup function
setup() {
    print_status "Setting up PolyTrans development environment..."
    
    # Build containers
    print_status "Building Docker containers..."
    docker compose build
    
    # Install dependencies
    print_status "Installing Composer dependencies..."
    docker compose run --rm polytrans-dev composer install
    
    print_status "Setup complete! You can now run quality checks."
    echo ""
    echo "Try: ./dev.sh phpcs"
}

# Run PHPCS
run_phpcs() {
    print_status "Running PHP CodeSniffer..."
    docker compose run --rm polytrans-dev composer run phpcs
}

# Run PHPCBF
run_phpcbf() {
    print_status "Running PHP Code Beautifier..."
    docker compose run --rm polytrans-dev composer run phpcbf
}

# Run PHPMD
run_phpmd() {
    print_status "Running PHP Mess Detector..."
    docker compose run --rm polytrans-dev composer run phpmd
}

# Run tests
run_tests() {
    print_status "Running PHPUnit tests..."
    docker compose run --rm polytrans-dev composer run test
}

# Run tests with coverage
run_coverage() {
    print_status "Running tests with coverage..."
    docker compose run --rm polytrans-dev composer run test-coverage
}

# Open shell
open_shell() {
    print_status "Opening development shell..."
    docker compose run --rm polytrans-dev bash
}

# Clean up
clean() {
    print_status "Cleaning up Docker containers and images..."
    docker compose down --rmi all --volumes
    print_status "Cleanup complete!"
}

# Run all checks
# Smoke test on a real WordPress.
#
# docker-compose.yml only provides a PHP tooling container — no WordPress, no database — so
# `composer test` cannot catch anything that needs WordPress itself: activation fatals, table
# creation, REST route registration, Twig admin templates, or the Plugin Check errors that only
# appear on the current release. `outdated_tested_upto_header` is an ERROR the day WordPress
# ships a new version, and the reviewer runs on the current one.
#
# This mirrors the `plugin-check` CI job: mysql + wordpress:cli in Docker, the plugin copied in
# distribution shape (.distignore applied), everything staged outside the checkout so the root
# container cannot leave files the next checkout can't remove.
run_smoke() {
    local wp_version="${1:-}"
    local work_dir="${TMPDIR:-/tmp}/treetank-trans-smoke-$$"
    local net="pt-smoke-$$"
    local db="pt-smoke-db-$$"

    print_status "Staging distribution tree in ${work_dir}"
    mkdir -p "$work_dir/wp-content/plugins/treetank-trans"
    rsync -a --exclude-from=.distignore --exclude='/.git' ./ "$work_dir/wp-content/plugins/treetank-trans/"

    docker network create "$net" >/dev/null
    print_status "Starting database"
    docker run -d --name "$db" --network "$net" -e MYSQL_ROOT_PASSWORD=pcp -e MYSQL_DATABASE=wp mysql:5.7 >/dev/null
    for _ in $(seq 1 40); do
        docker exec "$db" mysqladmin ping -uroot -ppcp --silent >/dev/null 2>&1 && break
        sleep 2
    done

    local version_flag=""
    [ -n "$wp_version" ] && version_flag="--version=$wp_version"

    print_status "Installing WordPress ${wp_version:-(current)} and running checks"
    # --user root: WP-CLI writes the WordPress tree. Everything it touches lives under
    # $work_dir, never under the repository checkout.
    docker run --rm --network "$net" -v "$work_dir:/var/www/html" -w /var/www/html --user root \
        --entrypoint sh wordpress:cli-php8.2 -c "
        set -e
        WP='php -d memory_limit=2G /usr/local/bin/wp'
        \$WP core download $version_flag --allow-root --force >/dev/null
        \$WP config create --allow-root --dbname=wp --dbuser=root --dbpass=pcp --dbhost=$db --skip-check --force >/dev/null
        \$WP core install --allow-root --url=http://example.test --title=smoke --admin_user=a --admin_password=a --admin_email=a@example.test --skip-email >/dev/null
        echo \"WordPress: \$(\$WP core version --allow-root)\"
        \$WP plugin activate treetank-trans --allow-root
        \$WP plugin install plugin-check --allow-root --activate >/dev/null 2>&1
        \$WP plugin check treetank-trans --allow-root --exclude-directories=vendor,node_modules --format=csv > pcp.csv 2>/dev/null
        echo \"Plugin Check errors:   \$(grep -c ',ERROR,' pcp.csv || true)\"
        echo \"Plugin Check warnings: \$(grep -c ',WARNING,' pcp.csv || true)\"
        grep ',ERROR,' pcp.csv || true
    "
    local status=$?

    print_status "Tearing down"
    docker rm -f "$db" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
    print_status "WordPress tree left in ${work_dir} for inspection"

    return $status
}

run_all() {
    print_status "Running all quality checks..."
    
    echo ""
    print_status "1/3 - Running PHP CodeSniffer..."
    if docker compose run --rm polytrans-dev composer run phpcs; then
        print_status "✓ PHPCS passed"
    else
        print_error "✗ PHPCS failed"
        return 1
    fi
    
    echo ""
    print_status "2/3 - Running PHP Mess Detector..."
    if docker compose run --rm polytrans-dev composer run phpmd; then
        print_status "✓ PHPMD passed"
    else
        print_warning "✗ PHPMD found issues"
    fi
    
    echo ""
    print_status "3/3 - Running tests..."
    if docker compose run --rm polytrans-dev composer run test; then
        print_status "✓ Tests passed"
    else
        print_error "✗ Tests failed"
        return 1
    fi
    
    echo ""
    print_status "All checks completed!"
}

# Main script logic
case "${1:-}" in
    setup)
        setup
        ;;
    phpcs)
        run_phpcs
        ;;
    phpcbf)
        run_phpcbf
        ;;
    phpmd)
        run_phpmd
        ;;
    test)
        run_tests
        ;;
    coverage)
        run_coverage
        ;;
    shell)
        open_shell
        ;;
    clean)
        clean
        ;;
    all)
        run_all
        ;;
    smoke)
        run_smoke "$2"
        ;;
    help|--help|-h)
        show_help
        ;;
    "")
        show_help
        ;;
    *)
        print_error "Unknown command: $1"
        echo ""
        show_help
        exit 1
        ;;
esac
