# PHP WebTrawler — guía de inicio (español)

Conjunto de herramientas para PHP 8.4+ que descarga páginas HTTP, analiza el DOM (CSS/XPath), rastrea sitios de forma
concurrente, renderiza páginas con JavaScript mediante un navegador y exporta los resultados. La documentación
completa está en [inglés](../en/README.md) y en [árabe](../ar/README.md); esta página resume lo esencial.

## Instalación

```bash
composer require helturkey/php-webtrawler
```

## Descargar y analizar una página

```php
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(
    defaultOptions: new FetchOptions(timeout: 15, blockPrivateNetworks: true),
    enforceDefaultSecurity: true,
);
$page = $scraper->get('https://example.com/');
echo $page->title(), "\n";
echo $page->markdown(mainContent: true);          // Markdown para RAG / LLM
foreach ($page->css('a[href]') as $enlace) { echo $enlace->attr('href'), "\n"; }
```

## Rastrear un sitio

```php
final class LibrosSpider extends CrawlSpider
{
    protected array $startUrls = ['https://books.example/'];
    protected array $allowedHosts = ['books.example'];
    protected bool $respectRobotsTxt = true;            // robots.txt: Disallow, Allow, Crawl-delay

    protected function rules(): array
    {
        return [
            new Rule(new LinkExtractor(allow: ['#/categoria/#'])),
            new Rule(new LinkExtractor(allow: ['#/libro/\d+#']), 'parseLibro'),
        ];
    }

    protected function parseLibro(Page $page, SpiderRequest $request): iterable
    {
        yield ['titulo' => $page->css('h1')->first()?->text()];
    }
}

foreach ($scraper->stream(new LibrosSpider()) as $elemento) { /* procesar cada elemento al llegar */ }
```

## Seguridad por defecto

* `blockPrivateNetworks` rechaza direcciones privadas, locales y de metadatos de la nube, **en cada redirección**.
* Lista de hosts permitidos, límite de tamaño de respuesta y exportaciones atómicas (archivos 0600).
* La biblioteca **detecta** bloqueos antibots (`BlockDetector`) pero no resuelve CAPTCHA.

## Línea de comandos y Docker

```bash
bin/scraper fetch https://example.com --markdown --block-private
bin/scraper shell https://example.com                  # explorador interactivo de selectores
bin/scraper crawl spider.php --robots --dedupe-db frontier.sqlite --format jsonl --output out.jsonl
docker build -f docker/Dockerfile -t webtrawler . && docker run --rm webtrawler version
```

Más información: [herramientas de rastreo](../en/crawling-toolkit.md), [referencia de servicios y
transportes](../en/services.md), [seguridad HTTP](../en/http-and-security.md).
