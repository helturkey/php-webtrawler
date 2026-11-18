# syntax=docker/dockerfile:1.7
#
# One Dockerfile, two deliverables:
#   runtime  - the small production CLI/MCP image (default target)
#   ci       - one AMD64 image containing the browser and native transport test rigs
#
# Run the complete Docker test workflow with:
#   composer test-docker
#
# The CI image depends on the runtime smoke stage, so the same command validates
# the production image and then runs browser, Impity, and php-rnet integration tests.

ARG PHP_IMAGE=php:8.4-cli-bookworm
ARG PHP_RNET_REF=v0.0.2
ARG CURL_IMPERSONATE_VERSION=2.3.1
ARG BUILD_JOBS=1

# ============================================================================================================
# Production runtime
# ============================================================================================================

FROM php:8.4-cli-alpine AS runtime-deps

RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install -j"$(nproc)" intl pcntl \
    && apk del .build-deps

FROM runtime-deps AS runtime-build

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Keep dependency installation cacheable when application source changes.
COPY composer.json ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer update --no-install --no-interaction --prefer-dist --no-progress \
    && composer validate --strict \
    && composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --optimize-autoloader

COPY bin ./bin
COPY src ./src
COPY config ./config

RUN composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction

FROM runtime-deps AS runtime-base

COPY --from=runtime-build /app /app

RUN adduser -D -H -u 10001 scraper \
    && mkdir -p /out \
    && chown scraper:scraper /out

USER scraper
WORKDIR /out

# Keep the production process unprivileged and bound its memory usage.
ENTRYPOINT ["php", "-d", "memory_limit=512M", "-d", "zend.assertions=-1", "/app/bin/scraper"]
CMD ["help"]

# The CI target depends on this stage. A failure here prevents the CI image
# from being produced, while the production image remains free of test tools.
FROM runtime-base AS smoke

RUN set -eu; \
    test "$(id -u)" = "10001"; \
    modules="$(php -m)"; \
    for extension in curl dom libxml mbstring pdo_sqlite intl pcntl posix; do \
        printf '%s\n' "$modules" | grep -qx "$extension" || { echo "missing PHP extension: $extension" >&2; exit 1; }; \
    done; \
    test ! -d /app/tests; \
    test ! -d /app/vendor/phpunit; \
    test ! -d /app/vendor/phpstan; \
    test ! -e /usr/bin/composer; \
    php /app/bin/scraper version >/dev/null; \
    mkdir -p /tmp/site; \
    printf '<h1>Hello</h1><p>from the image</p>' > /tmp/site/index.html; \
    php -S 127.0.0.1:8099 -t /tmp/site >/dev/null 2>&1 & \
    server_pid=$!; \
    trap 'kill "$server_pid" 2>/dev/null || true' EXIT; \
    ready=0; \
    attempt=0; \
    while [ "$attempt" -lt 30 ]; do \
        if php -r '$socket = @fsockopen("127.0.0.1", 8099, $errno, $error, 0.2); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);'; then \
            ready=1; \
            break; \
        fi; \
        attempt=$((attempt + 1)); \
        sleep 0.1; \
    done; \
    test "$ready" = "1" || { echo "local smoke fixture did not start" >&2; exit 1; }; \
    php /app/bin/scraper fetch http://127.0.0.1:8099/ --markdown | grep -q '^# Hello'; \
    if php /app/bin/scraper fetch http://127.0.0.1:8099/ --block-private >/dev/null 2>&1; then \
        echo "--block-private must refuse a loopback address" >&2; exit 1; \
    fi; \
    printf '%s\n' "smoke checks passed" > /tmp/smoke-passed; \
    echo "smoke checks passed"

# ============================================================================================================
# CI build prerequisites
# ============================================================================================================

FROM node:24-bookworm-slim AS node-runtime

FROM ${PHP_IMAGE} AS transport-ci

# Build and validate the production image before starting memory-intensive native compilation.
COPY --from=smoke /tmp/smoke-passed /tmp/webtrawler-smoke-passed

ARG TARGETARCH
ARG PHP_RNET_REF
ARG CURL_IMPERSONATE_VERSION
ARG BUILD_JOBS

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    IMPITY_CURL_IMPERSONATE_PATH=/usr/local/bin/curl-impersonate \
    PHP_CONFIG=/usr/local/bin/php-config \
    LIBCLANG_PATH=/usr/lib/llvm-18/lib \
    CARGO_HOME=/root/.cargo \
    RUSTUP_HOME=/root/.rustup \
    PATH=/root/.cargo/bin:${PATH} \
    CMAKE_BUILD_PARALLEL_LEVEL=${BUILD_JOBS} \
    MAKEFLAGS=-j${BUILD_JOBS}

RUN test "${TARGETARCH}" = "amd64" || { \
        echo "The native transport CI image requires linux/amd64; build with --platform=linux/amd64." >&2; \
        exit 1; \
    } \
    && case "${BUILD_JOBS}" in \
        ''|*[!0-9]*|0) echo "BUILD_JOBS must be a positive integer." >&2; exit 1 ;; \
    esac \
    && test "$(php-config --version | cut -d. -f1,2)" = "8.4"

# Install PHP extension headers and native build tools in one apt transaction.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        cmake \
        curl \
        git \
        gnupg \
        libcurl4-openssl-dev \
        libatomic1 \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        passwd \
        pkg-config \
        unzip \
        zip \
    && curl -fsSL --retry 5 --retry-all-errors \
        https://apt.llvm.org/llvm-snapshot.gpg.key \
        -o /tmp/llvm-snapshot.gpg.key \
    && gpg --dearmor \
        -o /usr/share/keyrings/llvm-archive-keyring.gpg \
        /tmp/llvm-snapshot.gpg.key \
    && echo "deb [signed-by=/usr/share/keyrings/llvm-archive-keyring.gpg] https://apt.llvm.org/bookworm/ llvm-toolchain-bookworm-18 main" \
        > /etc/apt/sources.list.d/llvm-18.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends clang-18 libclang-18-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/llvm-snapshot.gpg.key

# Keep PHP extension compilation and Rust compilation sequential. This avoids
# memory spikes under AMD64 emulation on developer machines.
RUN if ! php -r 'exit(extension_loaded("curl") ? 0 : 1);'; then \
        docker-php-ext-install -j"${BUILD_JOBS}" curl; \
    fi \
    && docker-php-ext-install -j"${BUILD_JOBS}" \
        dom \
        iconv \
        intl \
        mbstring \
        pdo_sqlite \
        xmlwriter \
        zip \
    && php -r '$required = ["curl", "dom", "iconv", "intl", "mbstring", "pdo_sqlite", "xmlwriter", "zip"]; $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension))); if ($missing !== []) { fwrite(STDERR, "Missing PHP extensions: " . implode(", ", $missing) . PHP_EOL); exit(1); }'

RUN curl --proto '=https' --tlsv1.2 -fsSL --retry 5 --retry-all-errors \
        https://sh.rustup.rs \
        -o /tmp/rustup-init.sh \
    && sh /tmp/rustup-init.sh -y --profile minimal --default-toolchain stable \
    && rm -f /tmp/rustup-init.sh \
    && rustc --version \
    && cargo --version

RUN git clone --depth 1 --branch "${PHP_RNET_REF}" \
        https://github.com/takielias/php-rnet.git /tmp/php-rnet

# Cache both downloaded crates and compiled artifacts between builds.
RUN --mount=type=cache,target=/root/.cargo/registry \
    --mount=type=cache,target=/root/.cargo/git \
    --mount=type=cache,target=/tmp/php-rnet-target \
    CARGO_BUILD_JOBS="${BUILD_JOBS}" \
    cargo build --release --locked --jobs "${BUILD_JOBS}" \
        --target-dir /tmp/php-rnet-target \
        --manifest-path /tmp/php-rnet/Cargo.toml \
    && test -s /tmp/php-rnet-target/release/librnet.so \
    && install -D -m 0644 /tmp/php-rnet-target/release/librnet.so \
        "$(php-config --extension-dir)/librnet.so" \
    && printf '%s\n' 'extension=librnet.so' \
        > "${PHP_INI_DIR}/conf.d/99-php-rnet.ini" \
    && php -r 'if (!extension_loaded("php-rnet") || !class_exists("RNet\\ClientBuilder")) { fwrite(STDERR, "php-rnet did not load for this PHP runtime.\n"); exit(1); }' \
    && ldd "$(php-config --extension-dir)/librnet.so" > /tmp/php-rnet-ldd.txt \
    && ! grep -q 'not found' /tmp/php-rnet-ldd.txt \
    && php --ri php-rnet >/dev/null

# Install the curl-impersonate binary required by dekor/php-impity.
RUN curl -fsSL --retry 5 --retry-all-errors \
        "https://github.com/lexiforest/curl-impersonate/releases/download/v${CURL_IMPERSONATE_VERSION}/curl-impersonate-v${CURL_IMPERSONATE_VERSION}.x86_64-linux-gnu.tar.gz" \
        -o /tmp/curl-impersonate.tar.gz \
    && tar -tzf /tmp/curl-impersonate.tar.gz | grep -Fx 'curl-impersonate' \
    && tar -xzf /tmp/curl-impersonate.tar.gz -C /usr/local/bin curl-impersonate \
    && chmod 0755 /usr/local/bin/curl-impersonate \
    && rm -f /tmp/curl-impersonate.tar.gz \
    && /usr/local/bin/curl-impersonate --version

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install exactly the development dependencies recorded in composer.lock.
COPY composer.json ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer update --no-install --no-interaction --prefer-dist --no-progress \
    && composer validate --strict \
    && composer install \
        --no-interaction \
        --prefer-dist \
        --no-progress

# Source and tests are copied after dependency installation to preserve the
# Composer layer cache during ordinary code and documentation changes.
COPY . /app

RUN groupadd --gid 10001 scraper \
    && useradd \
        --uid 10001 \
        --gid scraper \
        --create-home \
        --home-dir /home/scraper \
        --shell /usr/sbin/nologin \
        scraper \
    && chown -R scraper:scraper /app /home/scraper \
    && rm -f /tmp/webtrawler-smoke-passed

ENV HOME=/home/scraper

# ============================================================================================================
# Unified CI image
# ============================================================================================================

FROM transport-ci AS ci

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    SCRAPER_RUN_BROWSER_INTEGRATION=1

# Reuse Node/npm from the official Node image without installing a second
# Node distribution or running an unpinned npm install.
COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node-runtime /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN ln -sfn ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sfn ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version \
    && npm --version

# Install the browser and its OS libraries as root; tests run as scraper.
USER root

RUN install -d -o root -g root -m 0755 "${PLAYWRIGHT_BROWSERS_PATH}" \
    && vendor/bin/playwright-install --with-deps chromium \
    && test -x /usr/local/bin/node \
    && test -x /usr/local/bin/npm \
    && test -d "${PLAYWRIGHT_BROWSERS_PATH}" \
    && chown -R root:scraper "${PLAYWRIGHT_BROWSERS_PATH}" \
    && chmod -R g+rX "${PLAYWRIGHT_BROWSERS_PATH}" \
    && rm -rf /var/lib/apt/lists/*

COPY --chmod=0755 docker/test-ci.sh /usr/local/bin/test-ci

RUN chown -R scraper:scraper /app /home/scraper

USER scraper

CMD ["/usr/local/bin/test-ci"]

# ============================================================================================================
# Default target: production runtime image
# ============================================================================================================

FROM runtime-base AS runtime
