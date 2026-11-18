# Docker

This directory contains the single Dockerfile and the scripts used by the project's Docker workflow.

- [`Dockerfile`](Dockerfile): production runtime and unified CI build stages.
- [`test.sh`](test.sh): one-command local Docker validation, invoked by `composer test-docker`.
- [`test-ci.sh`](test-ci.sh): sequential browser, Impity, and php-rnet test runner used inside the CI image.

Use the full guides for requirements, deployment, test coverage, architecture, and troubleshooting:

- [English Docker guide](../docs/en/docker.md)
- [دليل Docker بالعربية](../docs/ar/docker.md)

Run the complete Docker validation from the repository root:

```bash
composer test-docker
```

The command builds the `ci` target for `linux/amd64`, includes the production smoke stage in that build, then runs the unified CI image once with external networking disabled. The `runtime` target remains the default and is the only image intended for deployment.
