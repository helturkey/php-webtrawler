<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Cli;

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\LinkExtractor;
use HElturkey\WebTrawler\Support\Text;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use Throwable;

/**
 * An interactive exploration shell: fetch a page, try CSS selectors and XPath expressions, look at the links and
 * the Markdown, then copy the selectors that worked into a spider.
 *
 * It is a fixed command set read line by line, not a PHP evaluator: nothing the user (or a pasted script) types
 * is ever executed as code, and every download goes through the scraper's normal security policy.
 */
final class Shell
{
    private const ROW_LIMIT = 50;

    private ?Page $page = null;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private readonly Scraper $scraper,
        private readonly FetchOptions $options,
        private $input,
        private $output,
        private readonly bool $showPrompt = true,
    ) {
    }

    public function run(?string $initialUrl = null): int
    {
        $this->print("WebTrawler shell. Type \"help\" for commands, \"quit\" to leave.\n");
        if ($initialUrl !== null) {
            $this->dispatch('fetch', $initialUrl);
        }

        while (true) {
            if ($this->showPrompt) {
                $this->print('> ');
            }
            $line = fgets($this->input);
            if ($line === false) {
                return 0;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2) ?: [$line];
            $command = strtolower($parts[0]);
            if (in_array($command, ['quit', 'exit', 'q'], true)) {
                return 0;
            }
            $this->dispatch($command, $parts[1] ?? '');
        }
    }

    private function dispatch(string $command, string $argument): void
    {
        try {
            match ($command) {
                'help', '?' => $this->help(),
                'fetch', 'open', 'get' => $this->fetch($argument),
                'url' => $this->print($this->page()->url() . "\n"),
                'title' => $this->print(($this->page()->title() ?? '(no title)') . "\n"),
                'text' => $this->print(Text::truncateChars($this->page()->text(), 2_000) . "\n"),
                'markdown', 'md' => $this->print(Text::truncateChars($this->page()->markdown($argument === '' ? null : $argument), 6_000) . "\n"),
                'css' => $this->elements($this->page()->css($this->required($argument, 'css <selector>'))),
                'xpath' => $this->elements($this->page()->xpath($this->required($argument, 'xpath <expression>'))),
                'count' => $this->print(count($this->page()->css($this->required($argument, 'count <selector>'))) . "\n"),
                'attr' => $this->attributes($argument),
                'links' => $this->links(),
                default => $this->print(sprintf("Unknown command \"%s\". Type \"help\".\n", $command)),
            };
        } catch (Throwable $exception) {
            $this->print('Error: ' . UrlSanitizer::sanitizeText($exception->getMessage()) . "\n");
        }
    }

    private function help(): void
    {
        $this->print(<<<'TXT'
fetch <url>               download a page and make it the current page
url | title | text        basic facts about the current page
css <selector>            elements matching a CSS selector (text, first 50)
xpath <expression>        elements matching an XPath expression
count <selector>          how many elements match
attr <name> <selector>    the attribute of every match, e.g. attr href a.product
links                     absolute links on the page (static assets removed)
markdown [selector]       the page, or part of it, as Markdown
quit                      leave the shell

TXT);
    }

    private function fetch(string $url): void
    {
        $url = $this->required($url, 'fetch <url>');
        $this->page = $this->scraper->get($url, $this->options);
        $this->print(sprintf("%s %s (%s)\n", $this->page->response()->status ?? '-', $this->page->title() ?? '(no title)', UrlSanitizer::sanitize($this->page->url())));
    }

    private function page(): Page
    {
        return $this->page ?? throw new \LogicException('No page yet. Use: fetch <url>');
    }

    private function required(string $argument, string $usage): string
    {
        if ($argument === '') {
            throw new \InvalidArgumentException('Usage: ' . $usage);
        }

        return $argument;
    }

    /** @param iterable<\HElturkey\WebTrawler\Parsing\Element> $elements */
    private function elements(iterable $elements): void
    {
        $shown = 0;
        $total = 0;
        foreach ($elements as $element) {
            $total++;
            if ($shown < self::ROW_LIMIT) {
                $this->print(sprintf("[%d] <%s> %s\n", $shown, $element->tag(), Text::truncateChars($element->text(), 160)));
                $shown++;
            }
        }
        $this->print($total === 0 ? "No matches.\n" : sprintf("%d match%s%s\n", $total, $total === 1 ? '' : 'es', $total > $shown ? " (showing $shown)" : ''));
    }

    private function attributes(string $argument): void
    {
        $parts = preg_split('/\s+/', $argument, 2) ?: [];
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Usage: attr <name> <selector>');
        }
        $shown = 0;
        foreach ($this->page()->css($parts[1]) as $element) {
            if ($shown >= self::ROW_LIMIT) {
                $this->print("... (more matches not shown)\n");
                break;
            }
            $this->print(Text::truncateChars((string) $element->attr($parts[0]), 200) . "\n");
            $shown++;
        }
        if ($shown === 0) {
            $this->print("No matches.\n");
        }
    }

    private function links(): void
    {
        $links = (new LinkExtractor())->extract($this->page());
        foreach (array_slice($links, 0, self::ROW_LIMIT) as $link) {
            $this->print(UrlSanitizer::sanitize($link) . "\n");
        }
        $this->print(sprintf("%d link%s%s\n", count($links), count($links) === 1 ? '' : 's', count($links) > self::ROW_LIMIT ? ' (showing ' . self::ROW_LIMIT . ')' : ''));
    }

    private function print(string $text): void
    {
        fwrite($this->output, $text);
    }
}
