#!/bin/sh
set -eu

: "${IMPITY_CURL_IMPERSONATE_PATH:=/usr/local/bin/curl-impersonate}"

if [ ! -x "${IMPITY_CURL_IMPERSONATE_PATH}" ]; then
    echo "curl-impersonate is missing or not executable: ${IMPITY_CURL_IMPERSONATE_PATH}" >&2
    exit 1
fi

php -r '
if (!extension_loaded("php-rnet") || !class_exists("RNet\\ClientBuilder")) {
    fwrite(STDERR, "The real php-rnet extension is not loaded.\n");
    exit(1);
}
'

"${IMPITY_CURL_IMPERSONATE_PATH}" --version

run_suite() {
    label="$1"
    shift
    log_file="$(mktemp)"

    printf '\n===== %s =====\n' "${label}"

    if "$@" >"${log_file}" 2>&1; then
        cat "${log_file}"
    else
        cat "${log_file}" >&2
        rm -f "${log_file}"
        return 1
    fi

    # An optional transport test that silently skips is not integration
    # coverage. The preflight checks above should make such skips unexpected.
    if grep -Eiq 'some tests were skipped|Skipped:[[:space:]]*[1-9][0-9]*' "${log_file}"; then
        echo "${label} did not execute every test; skipped tests are not accepted in this image." >&2
        rm -f "${log_file}"
        return 1
    fi

    rm -f "${log_file}"
}

run_suite "Impity + curl-impersonate" composer test-impity
run_suite "php-rnet real extension" composer test-rnet

printf '\nAll real transport integration tests passed without skips.\n'
