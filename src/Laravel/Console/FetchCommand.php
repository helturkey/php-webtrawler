<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Laravel\Console;

use HElturkey\WebTrawler\Scraper;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class FetchCommand extends Command
{
    protected $signature = 'scraper:fetch {url : Page to download} {--select= : CSS selector} {--attr= : Attribute to print instead of text}';

    protected $description = 'Download a page with the configured scraper and print the matching text or attributes.';

    public function handle(Scraper $scraper): int
    {
        $url = $this->argument('url');
        if (!is_string($url) || $url === '') {
            throw new InvalidArgumentException('The URL argument must be a non-empty string.');
        }

        $page = $scraper->get($url);
        $selector = $this->option('select');
        if (!is_string($selector) || $selector === '') {
            $this->line($page->text());

            return self::SUCCESS;
        }

        $attribute = $this->option('attr');
        foreach ($page->css($selector) as $element) {
            $this->line(is_string($attribute) && $attribute !== '' ? (string) $element->attr($attribute) : $element->text());
        }

        return self::SUCCESS;
    }
}
