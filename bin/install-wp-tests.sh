#!/usr/bin/env bash
# Install the WordPress core + test suite into /tmp so PHPUnit can run
# integration tests that require WP loaded. Standard WP-CLI scaffold pattern.
#
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> [wp-version] [skip-db-create]

set -euo pipefail

if [ "$#" -lt 4 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> <db-host> [wp-version] [skip-db-create]" >&2
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="$4"
WP_VERSION="${5:-trunk}"
SKIP_DB_CREATE="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
	local url="$1" out="$2"
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$url" -o "$out"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$out" "$url"
	else
		echo "error: neither curl nor wget found" >&2
		exit 1
	fi
}

resolve_wp_version() {
	if [ "$WP_VERSION" = "trunk" ] || [ "$WP_VERSION" = "nightly" ]; then
		echo "trunk"
		return
	fi

	if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
		# Latest patch for the minor: ask api.wordpress.org
		local resp
		resp=$(curl -fsSL "https://api.wordpress.org/core/version-check/1.7/?version=$WP_VERSION")
		echo "$resp" | grep -oE "\"version\":\"$WP_VERSION(\\.[0-9]+)?\"" | head -1 | sed -E 's/.*"version":"([^"]+)".*/\1/'
		return
	fi

	echo "$WP_VERSION"
}

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		echo "ok: WP core already at $WP_CORE_DIR"
		return
	fi
	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" = "trunk" ] || [ "$WP_VERSION" = "nightly" ]; then
		mkdir -p /tmp/wp-trunk
		download "https://wordpress.org/nightly-builds/wordpress-latest.zip" /tmp/wp-trunk.zip
		unzip -q -o /tmp/wp-trunk.zip -d /tmp/wp-trunk
		mv /tmp/wp-trunk/wordpress/* "$WP_CORE_DIR/"
	else
		local resolved
		resolved=$(resolve_wp_version)
		download "https://wordpress.org/wordpress-${resolved}.tar.gz" /tmp/wordpress.tar.gz
		tar -xzf /tmp/wordpress.tar.gz -C /tmp/
		mv /tmp/wordpress/* "$WP_CORE_DIR/"
	fi

	download "https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php" "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		svn co --quiet --ignore-externals \
			"https://develop.svn.wordpress.org/${WP_VERSION}/tests/phpunit/includes/" \
			"$WP_TESTS_DIR/includes"
		svn co --quiet --ignore-externals \
			"https://develop.svn.wordpress.org/${WP_VERSION}/tests/phpunit/data/" \
			"$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download \
			"https://develop.svn.wordpress.org/${WP_VERSION}/wp-tests-config-sample.php" \
			"$WP_TESTS_DIR/wp-tests-config.php"
		# Portable in-place sed (BSD + GNU)
		sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
		rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
	fi
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		echo "ok: skipping db create (already provided)"
		return
	fi

	local host port extra=""
	if [[ "$DB_HOST" == *:* ]]; then
		host="${DB_HOST%%:*}"
		port="${DB_HOST##*:}"
		extra="--host=$host --port=$port --protocol=tcp"
	else
		extra="--host=$DB_HOST --protocol=tcp"
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" $extra 2>/dev/null || true
}

install_wp
install_test_suite
install_db

echo "ok: WP test suite installed (core=$WP_CORE_DIR tests=$WP_TESTS_DIR version=$WP_VERSION)"
