# PHP WebTrawler — guide de démarrage (français)

Boîte à outils PHP 8.4+ pour récupérer des pages HTTP, analyser le DOM (CSS/XPath), explorer des sites de façon
concurrente, rendre des pages JavaScript via un navigateur et exporter les résultats. La documentation complète est
en [anglais](../en/README.md) et en [arabe](../ar/README.md) ; cette page résume l'essentiel.

## Installation

```bash
composer require helturkey/php-webtrawler
```

## Récupérer et analyser une page

```php
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(
    defaultOptions: new FetchOptions(timeout: 15, blockPrivateNetworks: true),
    enforceDefaultSecurity: true,
);
$page = $scraper->get('https://example.com/');
echo $page->title(), "\n";
echo $page->markdown(mainContent: true);          // Markdown pour RAG / LLM
foreach ($page->css('a[href]') as $lien) { echo $lien->attr('href'), "\n"; }
```

## Explorer un site

```php
final class LivresSpider extends CrawlSpider
{
    protected array $startUrls = ['https://books.example/'];
    protected array $allowedHosts = ['books.example'];
    protected bool $respectRobotsTxt = true;            // robots.txt : Disallow, Allow, Crawl-delay

    protected function rules(): array
    {
        return [
            new Rule(new LinkExtractor(allow: ['#/categorie/#'])),
            new Rule(new LinkExtractor(allow: ['#/livre/\d+#']), 'parseLivre'),
        ];
    }

    protected function parseLivre(Page $page, SpiderRequest $request): iterable
    {
        yield ['titre' => $page->css('h1')->first()?->text()];
    }
}

foreach ($scraper->stream(new LivresSpider()) as $element) { /* traiter chaque élément dès qu'il arrive */ }
```

## Sécurité par défaut

* `blockPrivateNetworks` refuse les adresses privées, locales et de métadonnées cloud, **à chaque redirection**.
* Liste d'hôtes autorisés, limite de taille de réponse, exports atomiques (fichiers 0600).
* La bibliothèque **détecte** les blocages anti-robots (`BlockDetector`) mais ne résout pas les CAPTCHA.

## Ligne de commande et Docker

```bash
bin/scraper fetch https://example.com --markdown --block-private
bin/scraper shell https://example.com                  # explorateur interactif de sélecteurs
bin/scraper crawl spider.php --robots --dedupe-db frontier.sqlite --format jsonl --output out.jsonl
docker build -f docker/Dockerfile -t webtrawler . && docker run --rm webtrawler version
```

Pour aller plus loin : [outils d'exploration](../en/crawling-toolkit.md), [référence des services et des
transports](../en/services.md), [sécurité HTTP](../en/http-and-security.md).
