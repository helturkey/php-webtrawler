<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create();
$browser = new PlaywrightBrowserDriver();

$quotes = $scraper->fetchRendered('https://quotes.toscrape.com/', $browser, [
    'wait_until' => 'domcontentloaded',
    'wait_for_selector' => '.quote',
]);

$quoteItems = $quotes->css('.quote')->map(static fn ($quote): array => [
    'text' => $quote->css('.text')->first()?->text(),
    'author' => $quote->css('.author')->first()?->text(),
]);

echo 'Quotes: ' . count($quoteItems) . PHP_EOL;
print_r($quoteItems);

$books = $scraper->fetchRendered('https://books.toscrape.com/', $browser, [
    'wait_until' => 'domcontentloaded',
    'wait_for_selector' => 'article.product_pod',
]);

$bookItems = $books->css('article.product_pod')->map(static fn ($book): array => [
    'title' => $book->css('h3 a')->first()?->attr('title'),
    'price' => $book->css('.price_color')->first()?->text(),
    'availability' => $book->css('.availability')->first()?->text(),
]);

echo 'Books on first page: ' . count($bookItems) . PHP_EOL;
print_r($bookItems);
