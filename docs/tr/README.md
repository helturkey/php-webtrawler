# PHP WebTrawler — başlangıç kılavuzu (Türkçe)

HTTP sayfalarını indiren, DOM'u (CSS/XPath) ayrıştıran, siteleri eşzamanlı tarayan, JavaScript'li sayfaları tarayıcıyla
işleyen ve sonuçları dışa aktaran PHP 8.4+ araç takımı. Tam belgeler [İngilizce](../en/README.md) ve
[Arapça](../ar/README.md) yazılmıştır; bu sayfa özeti verir.

## Kurulum

```bash
composer require helturkey/php-webtrawler
```

## Bir sayfayı indirip ayrıştırma

```php
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(
    defaultOptions: new FetchOptions(timeout: 15, blockPrivateNetworks: true),
    enforceDefaultSecurity: true,
);
$page = $scraper->get('https://example.com/');
echo $page->title(), "\n";
echo $page->markdown(mainContent: true);          // RAG / LLM için Markdown
foreach ($page->css('a[href]') as $baglanti) { echo $baglanti->attr('href'), "\n"; }
```

## Bir siteyi tarama

```php
final class KitapSpider extends CrawlSpider
{
    protected array $startUrls = ['https://books.example/'];
    protected array $allowedHosts = ['books.example'];
    protected bool $respectRobotsTxt = true;            // robots.txt: Disallow, Allow, Crawl-delay

    protected function rules(): array
    {
        return [
            new Rule(new LinkExtractor(allow: ['#/kategori/#'])),
            new Rule(new LinkExtractor(allow: ['#/kitap/\d+#']), 'parseKitap'),
        ];
    }

    protected function parseKitap(Page $page, SpiderRequest $request): iterable
    {
        yield ['baslik' => $page->css('h1')->first()?->text()];
    }
}

foreach ($scraper->stream(new KitapSpider()) as $oge) { /* her öğeyi gelir gelmez işle */ }
```

## Varsayılan güvenlik

* `blockPrivateNetworks`, özel/yerel adresleri ve bulut meta veri adreslerini **her yönlendirmede** reddeder.
* İzinli ana makine listesi, yanıt boyutu sınırı ve atomik dışa aktarma (0600 dosyalar).
* Kitaplık bot engellerini **algılar** (`BlockDetector`), CAPTCHA çözmez.

## Komut satırı ve Docker

```bash
bin/scraper fetch https://example.com --markdown --block-private
bin/scraper shell https://example.com                  # etkileşimli seçici gezgini
bin/scraper crawl spider.php --robots --dedupe-db frontier.sqlite --format jsonl --output out.jsonl
docker build -f docker/Dockerfile -t webtrawler . && docker run --rm webtrawler version
```

Daha fazlası: [tarama araçları](../en/crawling-toolkit.md), [servis ve taşıyıcı başvurusu](../en/services.md),
[HTTP güvenliği](../en/http-and-security.md).
