#!/usr/bin/env bash
# Installs WordPress and the WordPress PHPUnit test library for the integration tests.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

set -euo pipefail

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

if [ "$WP_VERSION" = "latest" ]; then
    WP_VERSION=$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*' | head -1 | sed 's/"version":"//')
fi

echo "Installing WordPress $WP_VERSION"

# WordPress core.
if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
    CORE=$(mktemp -d)
    curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar --strip-components=1 -zx -C "$CORE"
    rm -rf "$WP_CORE_DIR"
    mkdir -p "$(dirname "$WP_CORE_DIR")"
    mv "$CORE" "$WP_CORE_DIR"
fi

# Test library from wordpress-develop at the same version.
# It is built in a temporary directory and moved into place only when complete.
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    SRC=$(mktemp -d)
    git clone --quiet --depth=1 --branch "$WP_VERSION" --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop.git "$SRC/repo" 2>/dev/null
    git -C "$SRC/repo" sparse-checkout set tests/phpunit/includes tests/phpunit/data
    mkdir "$SRC/lib"
    cp -R "$SRC/repo/tests/phpunit/includes" "$SRC/repo/tests/phpunit/data" "$SRC/lib/"

    CONFIG="$SRC/lib/wp-tests-config.php"
    cp "$SRC/repo/wp-tests-config-sample.php" "$CONFIG"
    sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
    sed -i.bak "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
    sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$CONFIG"
    sed -i.bak "s/yourusernamehere/$DB_USER/" "$CONFIG"
    sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$CONFIG"
    sed -i.bak "s|localhost|$DB_HOST|" "$CONFIG"
    rm -f "$CONFIG.bak"

    rm -rf "$WP_TESTS_DIR"
    mkdir -p "$(dirname "$WP_TESTS_DIR")"
    mv "$SRC/lib" "$WP_TESTS_DIR"
    rm -rf "$SRC"
fi

echo "WordPress: $WP_CORE_DIR"
echo "Test library: $WP_TESTS_DIR"
