<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderItem;
use HElturkey\WebTrawler\Spider\SpiderRequest;

require dirname(__DIR__) . '/vendor/autoload.php';

$spider = new class () extends Spider {
    protected array $startUrls = ['https://books.toscrape.com'];
    protected array $allowedHosts = ['books.toscrape.com'];

    public function parse(Page $page, SpiderRequest $request): iterable
    {
        foreach ($page->css('.product_pod') as $book) {
            yield new SpiderItem([
                'title' => $book->css('h3')->first()?->text(),
                'url' => $book->css('h3 a')->first()?->attr('href'),
            ]);
        }

        foreach ($page->css('li.next a') as $link) {
            $href = $link->attr('href');
            if ($href !== null) {
                yield SpiderRequest::get($page->resolve($href));
            }
        }
    }
};

$result = Scraper::create()->crawl($spider);

foreach ($result->items as $item) {
    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
