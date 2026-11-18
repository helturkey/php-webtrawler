<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Cli;

use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Export\AtomicFile;
use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Export\JsonExporter;
use HElturkey\WebTrawler\Export\JsonlExporter;
use HElturkey\WebTrawler\Export\XmlExporter;
use HElturkey\WebTrawler\Throttle\AutoThrottle;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Proxy\ProxyPool;
use HElturkey\WebTrawler\Proxy\ProxyTransport;
use HElturkey\WebTrawler\Response\BlockDetector;
use HElturkey\WebTrawler\Retry\RetryPolicy;
use HElturkey\WebTrawler\Retry\RetryingTransport;
use HElturkey\WebTrawler\Transport\CachingTransport;
use HElturkey\WebTrawler\Transport\TransportRegistry;
use HElturkey\WebTrawler\Parsing\Element;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Pipeline\JsonlAppendPipeline;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;
use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Support\Text;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use HElturkey\WebTrawler\Version;
use Throwable;

/** Command-line front end: `scraper fetch`, `scraper crawl`. Output goes to the injected streams, never to globals. */
final class Application
{
    private const FORMATS = ['json', 'jsonl', 'csv', 'xml', 'text'];

    private const COMMON_VALUE = ['transport', 'emulation', 'timeout', 'retries', 'retry-delay', 'max-bytes', 'encoding', 'format', 'output', 'cache-dir', 'cache-ttl', 'user-agent'];
    private const COMMON_MULTI = ['proxy', 'header'];
    private const COMMON_FLAGS = ['block-private', 'help', 'offline'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var resource */
    private $stdin;

    private readonly TransportRegistry $transports;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param (callable(): bool)|null $shouldStop Polled during crawls (wire to SIGINT in bin/scraper).
     * @param (callable(array<string, mixed>): TransportInterface)|null $transportFactory Test seam.
     * @param TransportRegistry|null $transports Where --transport names are resolved; register custom ones here.
     * @param resource|null $stdin Read by the interactive shell.
     */
    public function __construct(
        $stdout = null,
        $stderr = null,
        private readonly mixed $shouldStop = null,
        private readonly mixed $transportFactory = null,
        ?TransportRegistry $transports = null,
        $stdin = null,
    ) {
        $this->transports = $transports ?? TransportRegistry::withDefaults();
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->stdin = $stdin ?? STDIN;
    }

    /** @param list<string> $argv Arguments without the program name. */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        try {
            return match ($command) {
                'fetch' => $this->fetch(array_slice($argv, 1)),
                'crawl' => $this->crawl(array_slice($argv, 1)),
                'shell' => $this->shell(array_slice($argv, 1)),
                'version', '--version', '-V' => $this->say(Version::userAgent() . "\n"),
                'help', '--help', '-h' => $this->help(),
                default => throw new UsageException(sprintf('Unknown command "%s". Run "scraper help".', $command)),
            };
        } catch (UsageException $exception) {
            $this->warn('Error: ' . $exception->getMessage() . "\n");

            return 2;
        } catch (Throwable $exception) {
            $this->warn('Error: ' . self::safeMessage($exception) . "\n");

            return 1;
        }
    }

    private function help(): int
    {
        return $this->say(<<<'TXT'
Usage:
  scraper fetch <url> [options]       Download one page and extract data
  scraper crawl <spider.php> [options] Run a spider (file returns a Spider, or use --class)
  scraper shell [url] [options]       Interactive shell: try selectors, links and Markdown on a page
  scraper version

Shared options:
  --transport NAME          HTTP backend: guzzle, amp, rnet, impity or a registered one [guzzle]
  --emulation NAME          php-rnet browser profile, e.g. CHROME_136
  --timeout SECONDS         request timeout                                    [30]
  --retries N               extra attempts on 429/5xx/network errors           [0]
  --retry-delay MS          base back-off between attempts (doubles each time) [500]
  --proxy URL               proxy (repeat for a rotating pool)
  --header "Name: value"    request header (repeatable)
  --max-bytes N             response size limit                                [20971520]
  --block-private           refuse loopback/private/link-local targets
  --encoding CHARSET        force the body charset (default: auto-detect)
  --format FMT              json | jsonl | csv | xml | text                    [json]
  --output FILE             write results to FILE instead of stdout
  --user-agent STRING       User-Agent header (also the name matched against robots.txt)
  --cache-dir DIR           development cache: keep GET responses on disk and reuse them (blocking only)
  --cache-ttl SECONDS       refetch cached entries older than this                [never]
  --offline                 with --cache-dir: never use the network; a missing entry is an error

fetch options:
  --select CSS | --xpath EXPR   elements to extract      --attr NAME   attribute instead of text
  --html                        outer HTML instead of text
  --markdown [--main-content]   print the page (or the --select part) as Markdown instead of extracting rows
  --item CSS --field "name=css[@attr]" (repeatable)      one row per item

crawl options:
  --class FQCN   --max-pages N   --max-depth N   --concurrency N   --delay MS
  --auto-throttle   --state FILE (resumable; needs --format jsonl --output FILE)
  --robots           obey robots.txt (Disallow/Allow and Crawl-delay)    --detect-blocks   count anti-bot challenges
  --dedupe-db FILE   keep the visited-URL set in SQLite instead of memory (needs pdo_sqlite)

Exit codes: 0 ok, 1 error, 2 usage error, 130 crawl stopped early (state saved).

TXT);
    }

    /** @param list<string> $arguments */
    private function fetch(array $arguments): int
    {
        $parsed = ArgumentParser::parse(
            $arguments,
            [...self::COMMON_VALUE, 'select', 'xpath', 'attr', 'item'],
            [...self::COMMON_MULTI, 'field'],
            [...self::COMMON_FLAGS, 'html', 'markdown', 'main-content'],
        );
        $options = $parsed['options'];
        if (isset($options['help'])) {
            return $this->help();
        }
        if (count($parsed['positional']) !== 1) {
            throw new UsageException('fetch needs exactly one URL.');
        }

        $format = $this->format($options);
        $scraper = $this->scraper($options, withRetryWrapper: true);
        $page = $scraper->get($parsed['positional'][0], $this->fetchOptions($options));

        if (isset($options['markdown'])) {
            return $this->emitMarkdown($page->markdown($this->string($options, 'select'), isset($options['main-content'])), $this->string($options, 'output'));
        }

        $rows = $this->extract($page, $options);

        return $this->emit($rows, $format, $this->string($options, 'output'));
    }

    /**
     * @param array<string, string|list<string>|true> $options
     * @return list<array<string, mixed>>
     */
    private function extract(Page $page, array $options): array
    {
        $attr = $this->string($options, 'attr');
        $html = isset($options['html']);
        $value = static fn (Element $element): string => $attr !== null ? (string) $element->attr($attr) : ($html ? $element->outerHtml() : $element->text());

        $item = $this->string($options, 'item');
        if ($item !== null) {
            $fields = [];
            foreach ($this->list($options, 'field') as $definition) {
                if (!str_contains($definition, '=')) {
                    throw new UsageException('--field must look like name=css or name=css@attribute.');
                }
                [$name, $spec] = explode('=', $definition, 2);
                $fieldAttr = null;
                if (preg_match('/^(.*)@([A-Za-z_:][A-Za-z0-9_:.\-]*)$/', $spec, $match) === 1) {
                    [, $spec, $fieldAttr] = $match;
                }
                $fields[$name] = [$spec, $fieldAttr];
            }
            if ($fields === []) {
                throw new UsageException('--item needs at least one --field.');
            }

            $rows = [];
            foreach ($page->css($item) as $element) {
                $row = [];
                foreach ($fields as $name => [$selector, $fieldAttr]) {
                    $found = $selector === '' ? $element : $element->css($selector)->first();
                    $row[$name] = $found === null ? null : ($fieldAttr !== null ? $found->attr($fieldAttr) : $found->text());
                }
                $rows[] = $row;
            }

            return $rows;
        }

        $selector = $this->string($options, 'select');
        $xpath = $this->string($options, 'xpath');
        if ($selector !== null && $xpath !== null) {
            throw new UsageException('Use either --select or --xpath, not both.');
        }
        if ($selector !== null || $xpath !== null) {
            $matches = $selector !== null ? $page->css($selector) : $page->xpath((string) $xpath);
            $rows = [];
            foreach ($matches as $element) {
                $rows[] = ['value' => $value($element)];
            }

            return $rows;
        }

        return [[
            'url' => $page->url(),
            'status' => $page->response()?->status,
            'title' => $page->title(),
            'text' => Text::truncateChars($page->text(), 20_000),
        ]];
    }

    /** @param list<string> $arguments */
    private function crawl(array $arguments): int
    {
        $parsed = ArgumentParser::parse(
            $arguments,
            [...self::COMMON_VALUE, 'class', 'max-pages', 'max-depth', 'concurrency', 'delay', 'state', 'dedupe-db'],
            self::COMMON_MULTI,
            [...self::COMMON_FLAGS, 'auto-throttle', 'robots', 'detect-blocks'],
        );
        $options = $parsed['options'];
        if (isset($options['help'])) {
            return $this->help();
        }
        if (count($parsed['positional']) !== 1) {
            throw new UsageException('crawl needs exactly one spider file.');
        }

        $spider = $this->loadSpider($parsed['positional'][0], $this->string($options, 'class'));
        $format = $this->format($options, 'jsonl');
        $output = $this->string($options, 'output');
        $statePath = $this->string($options, 'state');
        if ($statePath !== null && ($format !== 'jsonl' || $output === null)) {
            throw new UsageException('--state needs --format jsonl and --output FILE so finished items survive an interruption.');
        }

        $settings = [];
        foreach (['max-pages' => 'maxPages', 'max-depth' => 'maxDepth', 'concurrency' => 'concurrency', 'delay' => 'requestDelayMs'] as $flag => $setting) {
            if (($value = $this->string($options, $flag)) !== null) {
                $settings[$setting] = $this->integer($flag, $value);
            }
        }
        if (isset($options['auto-throttle'])) {
            $settings['autoThrottle'] = new AutoThrottle();
        }
        if (isset($options['robots'])) {
            $settings['respectRobotsTxt'] = true;
        }
        if (isset($options['detect-blocks'])) {
            $settings['blockDetector'] = BlockDetector::default();
        }
        if (($userAgent = $this->string($options, 'user-agent')) !== null) {
            $settings['userAgent'] = $userAgent;
        }
        if (($retries = $this->integer('retries', $this->string($options, 'retries') ?? '0')) > 0) {
            $settings['retryPolicy'] = $this->retryPolicy($options, $retries);
        }

        $streaming = $output !== null && $format === 'jsonl';
        $pipelines = [];
        if ($streaming) {
            $pipelines[] = new JsonlAppendPipeline((string) $output);
            $settings['collectItems'] = false;
        }
        $spider->configure($settings);

        $state = $statePath !== null ? new JsonCrawlStateStore($statePath) : null;
        // The SQLite frontier keeps the de-duplication set on disk instead of in memory: use it for crawls with
        // millions of URLs, or when several runs must share one visited-URL database.
        $dedupePath = $this->string($options, 'dedupe-db');
        $deduplicator = $dedupePath !== null ? new SQLiteCrawlDeduplicator($dedupePath) : null;
        // Retries during a crawl belong to the spider's non-blocking RetryPolicy (set above); wrapping the transport
        // as well would multiply attempts and would make the transport incompatible with concurrency.
        $result = $this->scraper($options, withRetryWrapper: false)->crawl($spider, [], $pipelines, $state, is_callable($this->shouldStop) ? $this->shouldStop : null, $deduplicator);

        $this->warn(sprintf(
            "Completed %d pages, %d items, %d errors, %d retries%s%s%s\n",
            $result->requestsCompleted,
            $result->itemsCount,
            count($result->errors),
            $result->requestsRetried,
            $result->resumed ? ' (resumed)' : '',
            ($result->robotsBlocked > 0 || $result->blockedResponses > 0) ? sprintf(' [robots.txt skipped %d, blocked %d]', $result->robotsBlocked, $result->blockedResponses) : '',
            $result->interrupted ? ' - stopped early, state saved' : '',
        ));
        foreach (array_slice($result->errors, 0, 10) as $error) {
            $this->warn(sprintf("  ! %s: %s\n", UrlSanitizer::sanitize($error['url']), UrlSanitizer::sanitizeText($error['message'])));
        }

        if (!$streaming) {
            $code = $this->emit($result->items, $format, $output);
            if ($code !== 0) {
                return $code;
            }
        }

        return $result->interrupted ? 130 : 0;
    }

    private function loadSpider(string $file, ?string $class): Spider
    {
        if (!is_file($file)) {
            throw new UsageException(sprintf('Spider file not found: %s', $file));
        }
        // The file is trusted code supplied by the operator, exactly like a PHPUnit or Composer script.
        $returned = (static fn (string $path): mixed => require $path)($file);
        if ($returned instanceof Spider) {
            return $returned;
        }
        if ($class === null) {
            throw new UsageException('The spider file must return a Spider instance, or pass --class.');
        }
        if (!class_exists($class) || !is_subclass_of($class, Spider::class)) {
            throw new UsageException(sprintf('%s is not a Spider class.', $class));
        }

        return new $class();
    }

    /**
     * Both commands share the same FetchOptions. They are enforced as a policy, so even a spider request that
     * brings its own options cannot switch off --block-private or exceed --max-bytes.
     *
     * @param array<string, string|list<string>|true> $options
     */
    private function scraper(array $options, bool $withRetryWrapper): Scraper
    {
        return Scraper::create($this->transport($options, $withRetryWrapper), null, null, $this->fetchOptions($options), enforceDefaultSecurity: true);
    }

    /**
     * @param array<string, string|list<string>|true> $options
     */
    private function transport(array $options, bool $withRetryWrapper): TransportInterface
    {
        // The factory replaces only the base transport; proxy and retry layering below always applies.
        $transport = null;
        if (is_callable($this->transportFactory)) {
            $built = ($this->transportFactory)($options);
            $transport = $built instanceof TransportInterface ? $built : null;
        }
        if ($transport === null) {
            $name = $this->string($options, 'transport') ?? 'guzzle';
            if (!$this->transports->has($name)) {
                throw new UsageException(sprintf('--transport must be one of: %s.', implode(', ', $this->transports->names())));
            }
            $transport = $this->transports->create($name, ['emulation' => $this->string($options, 'emulation')]);
        }

        $proxies = $this->list($options, 'proxy');
        if ($proxies !== []) {
            $transport = new ProxyTransport($transport, new ProxyPool($proxies));
        }
        // Retries wrap the proxy layer so each attempt can use the next healthy proxy. Only for blocking one-off
        // fetches: RetryingTransport is not async-capable.
        $retries = $this->integer('retries', $this->string($options, 'retries') ?? '0');
        if ($withRetryWrapper && $retries > 0) {
            $transport = new RetryingTransport($transport, $this->retryPolicy($options, $retries));
        }

        // The development cache is outermost: a hit costs no proxy, retry or network.
        $cacheDirectory = $this->string($options, 'cache-dir');
        if ($cacheDirectory !== null) {
            $ttl = $this->string($options, 'cache-ttl');
            $transport = new CachingTransport($transport, $cacheDirectory, $ttl !== null ? $this->integer('cache-ttl', $ttl) : null, isset($options['offline']));
        } elseif (isset($options['offline'])) {
            throw new UsageException('--offline needs --cache-dir.');
        }

        return $transport;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function retryPolicy(array $options, int $retries): RetryPolicy
    {
        $delay = $this->integer('retry-delay', $this->string($options, 'retry-delay') ?? '500');

        return new RetryPolicy(maxAttempts: $retries + 1, baseDelayMs: $delay);
    }

    /** @param array<string, string|list<string>|true> $options */
    private function fetchOptions(array $options): FetchOptions
    {
        $headers = [];
        foreach ($this->list($options, 'header') as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new UsageException('--header must look like "Name: value".');
            }
            $headers[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }
        if (($userAgent = $this->string($options, 'user-agent')) !== null && !array_key_exists('User-Agent', $headers)) {
            $headers['User-Agent'] = $userAgent;
        }

        return new FetchOptions(
            headers: $headers,
            timeout: (float) ($this->string($options, 'timeout') ?? 30),
            maxBodyBytes: ($limit = $this->string($options, 'max-bytes')) !== null ? $this->integer('max-bytes', $limit) : FetchOptions::DEFAULT_MAX_BODY_BYTES,
            blockPrivateNetworks: isset($options['block-private']),
            encoding: $this->string($options, 'encoding'),
        );
    }

    private function emitMarkdown(string $markdown, ?string $output): int
    {
        if ($output === null) {
            fwrite($this->stdout, $markdown . "\n");

            return 0;
        }
        AtomicFile::write($output, static function ($stream) use ($markdown): int {
            fwrite($stream, $markdown . "\n");

            return 1;
        });
        $this->warn(sprintf("Wrote Markdown to %s\n", $output));

        return 0;
    }

    /** @param list<string> $arguments */
    private function shell(array $arguments): int
    {
        $parsed = ArgumentParser::parse($arguments, self::COMMON_VALUE, self::COMMON_MULTI, self::COMMON_FLAGS);
        $options = $parsed['options'];
        if (isset($options['help'])) {
            return $this->help();
        }
        if (count($parsed['positional']) > 1) {
            throw new UsageException('shell takes at most one starting URL.');
        }

        $showPrompt = function_exists('stream_isatty') && stream_isatty($this->stdin);

        return (new Shell($this->scraper($options, withRetryWrapper: true), $this->fetchOptions($options), $this->stdin, $this->stdout, $showPrompt))
            ->run($parsed['positional'][0] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function emit(array $rows, string $format, ?string $output): int
    {
        $target = $output ?? tempnam(sys_get_temp_dir(), 'scraper-out-');
        if ($target === false) {
            throw new \RuntimeException('Unable to create a temporary output file.');
        }

        try {
            if ($format === 'text') {
                $lines = [];
                foreach ($rows as $row) {
                    $lines[] = count($row) === 1 ? (string) reset($row) : (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                }
                file_put_contents($target, $lines === [] ? '' : implode("\n", $lines) . "\n");
            } else {
                match ($format) {
                    'json' => (new JsonExporter())->export($rows, $target, true),
                    'jsonl' => (new JsonlExporter())->export($rows, $target),
                    'csv' => (new CsvExporter())->export($rows, $target, utf8Bom: $output !== null, neutralizeFormulas: true),
                    'xml' => (new XmlExporter())->export($rows, $target),
                    default => throw new UsageException('Unknown format ' . $format),
                };
            }

            if ($output === null) {
                fwrite($this->stdout, (string) file_get_contents($target));
            } else {
                $this->warn(sprintf("Wrote %d rows to %s\n", count($rows), $output));
            }
        } finally {
            if ($output === null && is_file($target)) {
                @unlink($target);
            }
        }

        return 0;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function format(array $options, string $default = 'json'): string
    {
        $format = $this->string($options, 'format') ?? $default;
        if (!in_array($format, self::FORMATS, true)) {
            throw new UsageException('--format must be one of: ' . implode(', ', self::FORMATS) . '.');
        }

        return $format;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function string(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, string|list<string>|true> $options
     * @return list<string>
     */
    private function list(array $options, string $name): array
    {
        $value = $options[$name] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function integer(string $name, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new UsageException(sprintf('--%s needs a non-negative integer.', $name));
        }

        return (int) $value;
    }

    private function say(string $text): int
    {
        fwrite($this->stdout, $text);

        return 0;
    }

    private function warn(string $text): void
    {
        fwrite($this->stderr, $text);
    }

    private static function safeMessage(Throwable $exception): string
    {
        return UrlSanitizer::sanitizeText($exception->getMessage());
    }
}
