# Docker deployment and CI

The repository uses one Dockerfile with two deliverables:

- `runtime`: the production CLI/MCP image. This is the default target.
- `ci`: one AMD64 test image containing the browser and native transport test dependencies.

The CI image is not a deployment image. It includes compilers, development headers, Composer development dependencies, Node.js, Chromium, php-rnet, and curl-impersonate.

## Requirements

- Docker Engine or Docker Desktop with Buildx enabled.
- BuildKit support (the Dockerfile uses BuildKit cache mounts).
- For the complete CI image: the ability to build and run `linux/amd64`.
- On Apple Silicon, configure Docker/Colima with AMD64 emulation before running the full suite. Native transport coverage is intentionally restricted to Linux AMD64.

Check the installation:

```bash
docker version
docker buildx version
docker buildx ls
```

## Production image

Build the default target:

```bash
docker buildx build \
  --file docker/Dockerfile \
  --target runtime \
  --load \
  --tag webtrawler:local \
  .
```

Run a command:

```bash
docker run --rm webtrawler:local version

docker run --rm webtrawler:local \
  fetch https://example.com/ --block-private --markdown
```

The image uses PHP CLI on Alpine and contains production Composer dependencies only. It copies `bin/`, `src/`, and `config/`; it does not copy the test suite, development dependencies, or the host's `vendor/` directory.

### Persistent crawl output

The image runs as UID `10001` (`scraper`) and uses `/out` as its working directory. Mount a host directory there when output or resume state must survive container removal:

```bash
mkdir -p out
docker run --rm \
  --mount "type=bind,source=$PWD/out,target=/out" \
  webtrawler:local \
  crawl /spiders/books.php \
  --format jsonl \
  --output /out/books.jsonl \
  --state /out/books.state.json \
  --robots
```

If a spider is supplied from the host, mount it read-only:

```bash
docker run --rm \
  --mount "type=bind,source=$PWD/spiders,target=/spiders,readonly" \
  --mount "type=bind,source=$PWD/out,target=/out" \
  webtrawler:local \
  crawl /spiders/books.php --format jsonl --output /out/books.jsonl
```

Ensure the host output directory is writable by the container's UID `10001`, or adjust its ownership/permissions before starting the container.

### Runtime characteristics

The production image:

- runs as the unprivileged `scraper` user;
- writes crawl output under `/out`;
- disables PHP assertions;
- sets PHP's memory limit to `512M`;
- includes production dependencies only.

The memory limit is part of the image entrypoint. If an application needs a different limit, build a derived image or deliberately override the entrypoint; do not assume a runtime environment variable changes this PHP setting.

### Network security

The image does not create a network boundary. For untrusted URLs:

- enable the scraper's private-network protections and configure an appropriate host allow-list;
- keep TLS verification enabled;
- restrict container egress at the Docker host, firewall, or egress-proxy layer when the threat model requires it;
- do not treat application-level URL checks as a complete defence against DNS rebinding.

See [HTTP options and security](http-and-security.md) and [Browser rendering](browser.md).

## Complete Docker test suite

Run all Docker-specific checks through one Composer command:

```bash
composer test-docker
```

This invokes `docker/test.sh`, which:

1. builds the single `ci` target for `linux/amd64`;
2. builds the production runtime and executes its smoke checks as a dependency of the CI target;
3. loads one CI image into the local Docker engine;
4. runs that image once with external networking disabled;
5. executes the Playwright/Chromium, Impity/curl-impersonate, and php-rnet integration suites sequentially;
6. fails if a required integration test is skipped.

The test image is tagged `webtrawler:ci` by default. Override the local tag without editing the script:

```bash
WEBTRAWLER_CI_IMAGE=webtrawler:ci-local composer test-docker
```

Build output defaults to plain progress. To change the display mode:

```bash
DOCKER_BUILD_PROGRESS=auto composer test-docker
```

The test runner is deliberately sequential. The php-rnet build and PHP extension compilation use one build job by default to avoid memory exhaustion under AMD64 emulation.

### What is covered

| Check | When it runs | What it validates |
|---|---|---|
| Runtime smoke | During the CI image build | Production extensions, unprivileged UID, production-only contents, CLI startup, local HTTP fetch, and private-address blocking |
| Browser integration | When the CI container runs | Chromium rendering, response capture, session persistence, adaptive parsing, and blocked external egress |
| Impity integration | When the CI container runs | The real curl-impersonate executable and local redirect/policy/body-limit behavior |
| php-rnet integration | When the CI container runs | The compiled native extension and the real RNet adapter against the local test server |

The browser and transport tests run in the same container, but as separate sequential suites. The container is started with `--network none`; the browser egress-isolation assertion is enabled only for that isolated run.

## Build targets and customization

| Target | Purpose | Intended use |
|---|---|---|
| `runtime` | Production CLI/MCP image | Deployment |
| `smoke` | Production image plus build-time smoke checks | Dependency of `ci`; can also be built directly for debugging |
| `transport-ci` | Native transport build stage | Internal stage used by `ci` |
| `ci` | Unified browser and native transport test image | Local and CI validation |

The full test command uses `linux/amd64` because the php-rnet native extension is built and tested for that architecture. Do not publish or deploy the `ci` image.

The native build-job count defaults to one. For a machine with known spare memory, it can be changed for a direct CI image build:

```bash
docker buildx build \
  --file docker/Dockerfile \
  --target ci \
  --platform linux/amd64 \
  --build-arg BUILD_JOBS=2 \
  --load \
  --tag webtrawler:ci \
  .
```

The Composer and Cargo download/build caches use BuildKit cache mounts. They are local to the BuildKit builder and can be removed by pruning that builder's cache.

## Troubleshooting

### Buildx is unavailable

Install or enable the Docker Buildx CLI plugin, then verify:

```bash
docker buildx version
```

### Docker Engine is not reachable

Start Docker Desktop or Colima, then verify:

```bash
docker info
```

### AMD64 execution fails on Apple Silicon

The full test target is AMD64-only. Enable AMD64 emulation in the Docker VM and confirm that a simple `linux/amd64` container can start before retrying the full build.

### Native compilation runs out of memory

Keep `BUILD_JOBS=1` (the default), avoid building other large images concurrently, and allocate sufficient memory to the Docker VM. Increasing `BUILD_JOBS` can increase peak memory use substantially.

### Browser egress-isolation test is skipped

Use the provided `composer test-docker` command. It starts the CI image with `--network none` and sets `SCRAPER_BROWSER_EGRESS_ISOLATED=1` for that invocation only. Do not set this variable for a normal network-connected browser process.

### A test is skipped

The unified CI runner treats skipped browser and native transport tests as failures. Check that the CI target was built successfully and that the required native extension, browser, and curl-impersonate executable are present. Do not weaken the skip check to make an incomplete integration run pass.

## Image hygiene

The root `.dockerignore` excludes host dependencies, local build/test output, VCS metadata, and local environment files from the build context. The production target also uses explicit `COPY` instructions, so its contents do not depend on the files present in a developer's working directory.

Do not pass secrets through Docker build arguments or bake credentials into either image. Supply runtime secrets through the deployment platform's secret mechanism.
