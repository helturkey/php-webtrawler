#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)
IMAGE="${WEBTRAWLER_CI_IMAGE:-webtrawler:ci}"
PLATFORM="linux/amd64"
PROGRESS="${DOCKER_BUILD_PROGRESS:-plain}"

cd "${PROJECT_ROOT}"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker CLI was not found. Install Docker and retry." >&2
    exit 1
fi

if ! docker buildx version >/dev/null 2>&1; then
    echo "Docker Buildx is required. Install/enable the Docker Buildx CLI plugin." >&2
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "The Docker engine is not reachable. Start Docker/Colima and retry." >&2
    exit 1
fi

printf 'Building the unified CI image for %s: %s\n' "${PLATFORM}" "${IMAGE}"

docker buildx build \
    --file docker/Dockerfile \
    --target ci \
    --platform "${PLATFORM}" \
    --load \
    --progress "${PROGRESS}" \
    --tag "${IMAGE}" \
    .

printf '\nRunning the complete Docker test suite with external networking disabled.\n'

docker run --rm \
    --platform "${PLATFORM}" \
    --network none \
    --env SCRAPER_BROWSER_EGRESS_ISOLATED=1 \
    "${IMAGE}"

printf '\nDocker validation completed successfully.\n'
