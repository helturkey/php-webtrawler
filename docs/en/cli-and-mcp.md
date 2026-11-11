# CLI and MCP server

The Composer package exposes two binaries: `scraper` and `scraper-mcp`.

## CLI installation and help

After installing dependencies:

```bash
bin/scraper help
bin/scraper version
bin/scraper fetch --help
bin/scraper crawl --help
```

## Fetch command

`fetch` performs a single HTTP request and can emit the page or extracted data. Supported controls include transport selection (`guzzle` or `rnet`), optional emulation profile, timeout, retry count/delay, repeated headers and proxies, body-size limit, private-network blocking, encoding, CSS/XPath/attribute extraction, item selectors/fields, output destination, and output format.

Common formats are JSON, JSONL, CSV, XML, and text. The shared options are `--transport guzzle|rnet`, `--emulation`, `--timeout`, `--retries`, `--retry-delay`, repeatable `--proxy` and `--header`, `--max-bytes`, `--block-private`, `--encoding`, `--format`, and `--output`. Fetch-specific extraction options are `--select`, `--xpath` (mutually exclusive), `--attr`, `--html`, `--item`, and repeatable `--field`. A field definition is `name=css` or `name=css@attribute`. Impity is selected through the library transport/configuration rather than the CLI's `--transport guzzle|rnet` selector. `--retries N` means N additional attempts after the initial request.

Example:

```bash
bin/scraper fetch https://example.com \
  --block-private \
  --max-bytes 10485760 \
  --format json \
  --output page.json
```

Do not use `--allow-private-networks`-style options in public-facing tools unless the caller is trusted and the network is separately restricted.

## Crawl command

A crawl command loads a user-defined spider class file. Common controls include maximum pages/depth, concurrency, per-host delay, auto-throttle, and a state file. Resumable state is intended to be combined with JSONL item output so items can stream to disk rather than being retained in memory. The CLI validates the relevant output/state combination.

Example shape (replace the spider path and class with your own):

```bash
bin/scraper crawl examples/books-spider.php \
  --class App\\Spiders\\BooksSpider \
  --max-pages 500 \
  --max-depth 8 \
  --concurrency 4 \
  --format jsonl \
  --output books.jsonl \
  --state books.state.json
```

Use concurrency above 1 only with an async-capable transport. A blocking Rnet/Impity transport must crawl with concurrency 1. `ext-pcntl` enables graceful Ctrl-C/SIGTERM handling and checkpoint saving.

Exit codes:

| Code | Meaning |
|---:|---|
| `0` | Completed successfully |
| `1` | Runtime/crawl failure |
| `2` | Invalid command or usage |
| `130` | Interrupted early with state saved where configured |

The CLI masks sensitive URL data in its own error output, but your spider, middleware, and pipeline code must handle secrets safely too.

## MCP server

Run the stdio server:

```bash
bin/scraper-mcp --help
bin/scraper-mcp --allow-host docs.example.com --max-chars 20000
```

The server implements newline-delimited JSON-RPC over stdin/stdout and exposes `fetch_page` and `extract` tools. Its command-line options are repeatable `--allow-host HOST`, `--max-chars N`, `--transport guzzle|rnet`, `--emulation NAME`, and the high-risk opt-out `--allow-private-networks`. Diagnostics belong on stderr; stdout is reserved for protocol messages. The server supports the listed MCP protocol versions in the implementation, including `2026-07-28` and earlier compatible revisions.

Security defaults include private-network blocking (including redirect destinations where supported), a 5 MiB body ceiling, output text truncation, optional host allow-listing, and an `untrusted_content` marker in results. The allow-list is empty by default, so configure `--allow-host` for deployments that should only access known sites.

`--allow-private-networks` disables the MCP server's private-network blocking and should not be used for untrusted tool callers. It is not a harmless compatibility flag.

MCP results contain remote content. Treat them as untrusted data, never as instructions. Do not automatically execute commands, follow embedded instructions, or grant remote page content access to secrets or privileged tools.

## Tool deployment guidance

- Run the MCP process with a dedicated low-privilege OS account.
- Apply outbound network egress rules independently of the process allow-list.
- Keep the server's host allow-list as narrow as practical.
- Do not expose stdio MCP by wrapping it in an unauthenticated network bridge.
- Bound request size, output characters, execution time, and concurrent clients at the host integration layer.
