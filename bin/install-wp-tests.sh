#!/usr/bin/env bash
# Install WordPress test suite against local MySQL (database iworks_tests_sm).
# Usage: bin/install-wp-tests.sh [db_name] [db_user] [db_pass] [db_host] [wp_version]
# Resolves X.Y to the latest X.Y.Z archive and matching develop.svn tags/ ref
# so the PHPUnit suite matches the WordPress core under test (not trunk).
set -euo pipefail

DB_NAME="${1:-iworks_tests_sm}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="${ROOT}/tests/wordpress"
WP_CORE="${TMP}/wordpress"
WP_TESTS="${TMP}/wordpress-tests-lib"

mkdir -p "${TMP}"

download() {
	local url="$1"
	local out="$2"
	if command -v curl >/dev/null 2>&1; then
		curl -sSL "${url}" -o "${out}"
	else
		wget -q -O "${out}" "${url}"
	fi
}

ARCHIVE_NAME=""
TESTS_REF=""
RESOLVED="${WP_VERSION}"

if [[ "${WP_VERSION}" == "latest" || "${WP_VERSION}" == "nightly" || "${WP_VERSION}" == "trunk" ]]; then
	ARCHIVE_NAME="latest"
	TESTS_REF="trunk"
	RESOLVED="latest"
elif [[ "${WP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
	# X.Y → latest matching X.Y.Z from the version-check API; tests from that tag.
	download "https://api.wordpress.org/core/version-check/1.7/" "${TMP}/wp-latest.json"
	VERSION_ESCAPED="${WP_VERSION//./\\.}"
	LATEST_VERSION="$(grep -o "\"version\":\"${VERSION_ESCAPED}[^\"]*" "${TMP}/wp-latest.json" | sed 's/"version":"//' | head -1 || true)"
	rm -f "${TMP}/wp-latest.json"
	if [[ -n "${LATEST_VERSION}" ]]; then
		RESOLVED="${LATEST_VERSION}"
		ARCHIVE_NAME="wordpress-${LATEST_VERSION}"
		TESTS_REF="tags/${LATEST_VERSION}"
	else
		RESOLVED="${WP_VERSION}"
		ARCHIVE_NAME="wordpress-${WP_VERSION}"
		TESTS_REF="branches/${WP_VERSION}"
	fi
elif [[ "${WP_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0]$ ]]; then
	# X.Y.0 is published as wordpress-X.Y / tags/X.Y
	STRIPPED="${WP_VERSION%??}"
	RESOLVED="${STRIPPED}"
	ARCHIVE_NAME="wordpress-${STRIPPED}"
	TESTS_REF="tags/${STRIPPED}"
elif [[ "${WP_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	RESOLVED="${WP_VERSION}"
	ARCHIVE_NAME="wordpress-${WP_VERSION}"
	TESTS_REF="tags/${WP_VERSION}"
else
	RESOLVED="${WP_VERSION}"
	ARCHIVE_NAME="wordpress-${WP_VERSION}"
	TESTS_REF="trunk"
fi

if [[ ! -d "${WP_CORE}/wp-includes" ]]; then
	echo "Downloading WordPress ${RESOLVED} (tests ${TESTS_REF})…"
	mkdir -p "${WP_CORE}"
	if [[ "${ARCHIVE_NAME}" == "latest" ]]; then
		download "https://wordpress.org/latest.tar.gz" "${TMP}/wordpress.tar.gz"
	else
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "${TMP}/wordpress.tar.gz"
	fi
	tar -xzf "${TMP}/wordpress.tar.gz" -C "${TMP}"
	rm -f "${TMP}/wordpress.tar.gz"
fi

if [[ ! -d "${WP_TESTS}/includes" ]]; then
	echo "Downloading WordPress test suite (${TESTS_REF})…"
	mkdir -p "${WP_TESTS}"
	svn co --quiet "https://develop.svn.wordpress.org/${TESTS_REF}/tests/phpunit/includes/" "${WP_TESTS}/includes"
	svn co --quiet "https://develop.svn.wordpress.org/${TESTS_REF}/tests/phpunit/data/" "${WP_TESTS}/data"
	download "https://develop.svn.wordpress.org/${TESTS_REF}/wp-tests-config-sample.php" "${WP_TESTS}/wp-tests-config-sample.php"
fi

CFG="${WP_TESTS}/wp-tests-config.php"
if [[ ! -f "${CFG}" ]]; then
	cp "${WP_TESTS}/wp-tests-config-sample.php" "${CFG}"
	perl -i -pe "s|dirname\\( __FILE__ \\) \\. '/src/'|'${WP_CORE}/'|" "${CFG}"
	perl -i -pe "s|youremptytestdbnamehere|${DB_NAME}|" "${CFG}"
	perl -i -pe "s|yourusernamehere|${DB_USER}|" "${CFG}"
	perl -i -pe "s|yourpasswordhere|${DB_PASS}|" "${CFG}"
	perl -i -pe "s|localhost|${DB_HOST}|" "${CFG}"
fi

echo "Ensuring database ${DB_NAME} exists…"
if [[ -z "${DB_PASS}" ]]; then
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" --host="${DB_HOST}" 2>/dev/null || true
else
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST}" 2>/dev/null || true
fi

echo "WP tests ready at ${WP_TESTS} (WordPress ${RESOLVED}, suite ${TESTS_REF})"
