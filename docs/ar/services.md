# مرجع الخدمات ووسائل النقل

توثّق هذه الصفحة كل خدمة في الحزمة: وظيفتها وطريقة إنشائها ومثالًا قصيرًا. جميع المسارات تحت
`HElturkey\WebTrawler\`. الطبقات منفصلة عمدًا، فيمكنك استبدال أي طبقة دون المساس بغيرها.

```
Scraper ──► Fetcher ──► Downloader ──► RequestPolicy   (قواعد الرابط والمضيف والشبكات الخاصة والحجم)
               │            └──────► TransportInterface  (Guzzle | Amp | Rnet | Impity | Browser | وسيلتك)
               └──► ResponseParser ──► Page ──► Element / ElementCollection
Scraper::crawl ──► SpiderRunner ──► Fetcher + Spider + Middlewares + Pipelines + الحالة + مزيل التكرار
```

## 1. نقطة الدخول

| الخدمة | الفضاء | الدور |
|---|---|---|
| `Scraper` | الجذر | واجهة موحدة: `fetch` و`get` و`post` و`session` و`crawl` و`fetchRendered` و`snapshotStore` |
| `Fetching\Fetcher` | `Fetching` | تنزيل ثم تحليل. استخدمها عند الحاجة إلى `fetchAsync()` أو إلى نصفي العملية منفصلين |
| `Fetching\Downloader` | `Fetching` | سياسة الرابط ثم وسيلة النقل ثم فحص الاستجابة. لا يحلل المحتوى |
| `Fetching\BrowserFetcher` | `Fetching` | يعرض الصفحة عبر أي `BrowserDriver` ثم يحللها بالمحلل نفسه |
| `Policy\RequestPolicy` | `Policy` | يدمج خيارات الطلب مع الإعدادات الافتراضية الملزمة |

```php
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(
    defaultOptions: new FetchOptions(timeout: 15, blockPrivateNetworks: true, allowedHosts: ['example.com']),
    enforceDefaultSecurity: true,   // خيارات الطلب لا يمكنها إلا أن تكون أشد من هذه القيم
);
$page = $scraper->get('https://example.com/');
```

## 2. خيارات الطلب والسياسة

* **`Options\FetchOptions`** – غير قابل للتعديل. المهلات وTLS والتحويلات وحد الحجم (الافتراضي 20 ميبيبايت) وقائمة المضيفين
  المسموحة وحجب الشبكات الخاصة والترميز. تعيد `tightenedBy($policy)` التركيبة الأشد، وتعيد `withExtraOption()` و`withAllowedHosts()` نسخًا معدلة.
* **`Policy\RequestPolicy`** – يستعملها `Downloader`. تطبّع `prepareUrl()` الرابط وتطبق قائمة المضيفين و`UrlGuard`،
  وتعيد `verify()` فحص حجم الجسم و**الرابط الفعلي** بعد التحويلات.
* **`Policy\UrlNormalizer`** – يقبل روابط http(s) سليمة فقط؛ يحوّل المخطط والمضيف إلى أحرف صغيرة، ويحوّل مضيفات IDN،
  ويحذف المنافذ الافتراضية والمقاطع `.` و`..`، ويجعل المسار الفارغ `/`.
* **`Policy\UrlGuard`** – يرفض المضيفات المحلية والخاصة والمحجوزة والأرقام الغامضة والأسماء التي لا تُحلّ (فشل مغلق).
  لا يمنع DNS rebinding؛ استخدم قواعد خروج شبكية مع المدخلات العدائية.
* **`Policy\HostPolicy`** – مطابقة المضيف تامة أو نطاق فرعي. و**`Policy\UrlOrigin`** تقارن المخطط والمضيف والمنفذ.

```php
use HElturkey\WebTrawler\Policy\UrlGuard;

UrlGuard::assertPublic('http://169.254.169.254/latest/meta-data/'); // يرمي UnsafeUrlException
```

## 3. وسائل النقل

تنفذ كل وسائل النقل `Contracts\Http\TransportInterface::send()` وتعيد `Response\FetchResponse`. والوسائل القادرة على
التنفيذ غير المتزامن تنفذ أيضًا `AsyncTransportInterface` (`supportsAsync()` و`sendAsync()` و`tick()`)، وتضيف
`CancellableTransportInterface` الدالة `cancelAll()`.

| وسيلة النقل | الصنف (`Transport\Drivers`) | غير متزامنة | المتطلبات | الاستخدام المعتاد |
|---|---|:-:|---|---|
| Guzzle | `GuzzleTransport` | نعم مع `ext-curl` ودون عميل محقون | أساسية | الافتراضية؛ Spider متزامن |
| Amp | `AmpTransport` | نعم | `amphp/http-client` 5 (و`amphp/http-tunnel` للبروكسي) | حلقة أحداث Revolt |
| php-rnet | `RnetTransport` | لا | امتداد أصلي | بصمات TLS/HTTP2 شبيهة بالمتصفح |
| Impity | `ImpityTransport` | لا | `dekor/php-impity` و`curl-impersonate` | وسيلة PSR-18 ببصمة متصفح |
| Browser | `BrowserTransport` | لا | أي `BrowserDriver` | صفحات JavaScript عبر `Scraper::fetch()` |

الخيارات المدعومة: `headers` و`timeout` و`connect_timeout` و`verify` و`allow_redirects` و`proxy` و`cookies` و`query` و`form_params` و`json` و`body`،
إضافة إلى مفاتيح السياسة `max_body_bytes` و`block_private_networks` و`allowed_hosts`. ترفض Rnet وImpity الخيارات `multipart` و`sink` و`stream` و`auth`.

تتبع كل وسائل النقل المرفقة القواعد نفسها: تتبع التحويلات بنفسها ليُفحص **كل قفزة** مقابل قائمة المضيفين وحارس الشبكات الخاصة،
وتُسقط بيانات الاعتماد عند التحويل لأصل آخر، وتفرض حد الحجم أثناء القراءة حيث يسمح المحرك، وتبلّغ بالرابط الفعلي.

```php
use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$scraper = Scraper::create(transport: new GuzzleTransport());   // الافتراضية
$scraper = Scraper::create(transport: new AmpTransport());      // مبنية على Revolt
```

### TransportRegistry

يربط `Transport\TransportRegistry` اسمًا بمصنع لتختار الإعدادات (خيار `--transport` في CLI و`config('scraper.transport')` في Laravel)
وسيلة النقل. تسجل `withDefaults()` الأسماء `guzzle` و`amp` و`rnet` و`impity`.

```php
use HElturkey\WebTrawler\Transport\TransportRegistry;

$registry = TransportRegistry::withDefaults();
$registry->register('internal-proxy', fn (array $config) => new MyCompanyTransport());
$transport = $registry->create('internal-proxy');
```

الاسم أحرف لاتينية صغيرة وأرقام و`-` و`_` ويبدأ بحرف.

## 4. المغلِّفات: إعادة المحاولة والبروكسي والتخفيف

* **`Retry\RetryPolicy`** – منطق قرار خالص (عدد المحاولات والتراجع الأسي والتشويش `none|equal|full` وحالات إعادة المحاولة
  والطرق الآمنة و`Retry-After`). تعطّل `RetryPolicy::none()` إعادة المحاولة.
* **`Retry\RetryingTransport`** – غلاف متزامن للطلبات المفردة. لا تستخدمه حول وسيلة نقل غير متزامنة للزحف؛
  فلـ SpiderRunner مجدول إعادة محاولة غير حاجب خاص به.
* **`Proxy\ProxyPool`** – `round_robin` و`random` و`least_failures`؛ قاطع دائرة لكل بروكسي مع فترة تهدئة ومسبار واحد نصف مفتوح؛
  وتُخفى بيانات الاعتماد في `stats()`.
* **`Proxy\ProxyTransport`** – يوجّه كل طلب عبر المجموعة ويعيد النتيجة إليها. المجموعة **مسار ملزم**: يُرفض الطلب الذي يحمل
  `proxy` خاصًا به ما لم تمرر `allowExplicitProxyOverride: true`. والاتصال المباشر عند نفاد المجموعة يتطلب `allowDirectFallback: true`.
* **`Throttle\AutoThrottle`** – تأخير لكل مضيف مشتق من زمن الاستجابة والتزامن المستهدف؛ والأخطاء لا تسرّعه أبدًا.

```php
use HElturkey\WebTrawler\Proxy\{ProxyPool, ProxyTransport};
use HElturkey\WebTrawler\Retry\{RetryPolicy, RetryingTransport};
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$pool = new ProxyPool([getenv('PROXY_A'), getenv('PROXY_B')], ProxyPool::LEAST_FAILURES, 3, 60);
$transport = new RetryingTransport(
    new ProxyTransport(new GuzzleTransport(), $pool),
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 300, jitter: RetryPolicy::JITTER_FULL),
);
```

## 5. أداة التزامن

`Concurrency\Pending` نتيجة تُحسم مرة واحدة، وفيها `resolve()` و`reject()` و`isSettled()` و`value()` و`error()` و`onSettled()` و`then()`.
ليست مكتبة وعود عمدًا: تحسمها وسيلة النقل من مدخلاتها ومخرجاتها، ويقرؤها المستهلك بعد عودة `tick()`. والحسم الثاني يُتجاهل.

```php
use HElturkey\WebTrawler\Concurrency\Pending;

$pending = new Pending();
$mapped = $pending->then(fn ($response) => $response->status);
$pending->resolve($fetchResponse);
echo $mapped->value();
```

## 6. التحليل

* **`Contracts\ResponseParser`** و**`Parsing\HtmlPageParser`** – يحوّلان `FetchResponse` إلى `Page`
  (شجرة HTML5 عبر Symfony؛ ترتيب الترميز: الخيار الصريح ثم BOM ثم الترويسة ثم `<meta>` ثم UTF-8 سليم).
* **`Parsing\Page`** – `css()` و`xpath()` و`title()` و`text()` و`html()` و`url()` و`resolve()` و`response()` و
  `networkRequests()` و`apiResponses()` و`failedRequests()` و`remember()` و`adaptive()`.
* **`Parsing\Element` و`ElementCollection`** – التنقل والوصول إلى السمات؛ والمجموعات قابلة للعد والتكرار.
* **`Response\FetchResponse`** – `status` و`headers` و`body` و`requestedUrl` و`effectiveUrl` و`durationMs` و`meta`؛ ودوال مساعدة
  `isSuccessful()` و`header()` و`json()` و`contentHash()`.

```php
$rows = $page->css('table.prices tr')->map(static fn ($tr): array => [
    'name'  => $tr->css('td.name')->first()?->text(),
    'price' => $tr->css('td.price')->first()?->text(),
]);
```

## 7. الجلسات

يحتفظ `Session\Session` بحاوية كوكيز عبر الطلبات (`get` و`post` و`fetch` و`cookies`). ولا يمثّل تخزين المتصفح ولا تبويباته.
لحالة المتصفح استخدم `BrowserSessionState` (انظر [تشغيل المتصفح](browser.md)).

## 8. المطابقة التكيفية ومخازن اللقطات

تنفذ `AdaptiveMatcher` و`AdaptiveScoringProfile` و`ElementSnapshot` و`AdaptiveSelection` و`AdaptiveResult` و`AdaptiveMatch`
مطابقة بالتشابه المرتّب (انظر [المحددات التكيفية](adaptive-selectors.md)). وتُخزَّن اللقطات عبر `Contracts\SnapshotStore`:

| المخزن | النطاق | ملاحظات |
|---|---|---|
| `InMemorySnapshotStore` | عملية واحدة | الافتراضي |
| `JsonSnapshotStore` | جهاز واحد وقرص محلي | قفل واستبدال ذري |
| `SQLiteSnapshotStore` | عدة عمليات على جهاز واحد | أمر UPSERT واحد لكل كتابة وWAL واتصال كسول يُعاد فتحه بعد fork |

```php
$scraper = Scraper::create(snapshotStore: new SQLiteSnapshotStore(__DIR__ . '/var/snapshots.sqlite'));
```

## 9. خدمات الزحف

* **`Spider\Spider`** – ورّثه ونفّذ `parse()`؛ الإعدادات `startUrls` و`allowedHosts` و`maxPages` و`maxDepth` و`requestDelayMs` و
  `concurrency` و`collectItems` و`retryPolicy` و`autoThrottle`.
* **`Spider\SpiderRunner`** – تنزيلات متوازية محدودة تُعالج بترتيب الانتهاء؛ ويجري التحليل وMiddlewares وPipelines دائمًا على الخيط المستدعي.
* **`Spider\RequestFingerprint`** – هوية الطلب بـ SHA-256 (الطريقة والرابط القانوني دون الجزء `#` والحمولة وخيارات الطلب).
* **`Spider\JsonCrawlStateStore`** – نقطة حفظ ذرية بصلاحية 0600 للزحف القابل للاستئناف (تسليم مرة واحدة على الأقل).
* **`Contracts\CrawlDeduplicator`** و**`Storage\SQLiteCrawlDeduplicator`** – مجموعة روابط ومحتوى مطابق على القرص لكل نطاق زحف.
  المفتاح الأساسي في SQLite هو المرجع، ومرشح Bloom يتجاوز الاستعلام للغائبين قطعًا فقط.
  وتحذف `purgeOlderThan($seconds)` السجلات التي خلّفها زحف قُتلت عمليته.
* **`Contracts\DownloaderMiddleware`** و**`Contracts\ItemPipeline`** و**`Pipeline\JsonlAppendPipeline`** – نقاط التوسعة.

```php
$deduplicator = new SQLiteCrawlDeduplicator(__DIR__ . '/var/frontier.sqlite', expectedUrls: 2_000_000);
$result = $scraper->crawl($spider, state: $state, crawlDeduplicator: $deduplicator);
printf("%d pages, %d byte-identical duplicates\n", $result->requestsCompleted, $result->duplicateContentResponses);

// مهمة صيانة ليلية: احذف سجلات الواجهة الأمامية الأقدم من 3 أيام.
$deduplicator->purgeOlderThan(3 * 86400);
```

### خدمات أدوات الزحف

| الخدمة | الدور |
|---|---|
| `Policy\RobotsTxt` و`Policy\RobotsPolicy` | محلل RFC 9309 وسياسة مخزنة لكل أصل (`Spider::$respectRobotsTxt`) |
| `Spider\LinkExtractor` و`Spider\Rule` و`Spider\CrawlSpider` | تتبع الروابط بالتصريح |
| `Spider\SitemapSpider` و`Parsing\SitemapParser` | الزحف من خرائط الموقع (الفهارس وgzip وقاعدة المضيف نفسه) |
| `Response\BlockDetector` و`Response\BlockSignal` | كشف تحديات مكافحة الروبوتات وتحديد المعدل (ولا تحلها أبدًا) |
| `Transport\CachingTransport` | ذاكرة تطوير مؤقتة مع إعادة تشغيل دون اتصال |
| `Parsing\MarkdownConverter` | HTML إلى Markdown خلف `Page::markdown()` |
| `Contracts\BatchCrawlDeduplicator` | تسجيل الروابط في معاملة واحدة (`SQLiteCrawlDeduplicator`) |
| `Cli\Shell` | صدفة استكشاف تفاعلية |
| `Scraper::stream()` و`crawl(sessions: [...])` | بث العناصر؛ وسائل نقل مسماة لكل طلب |

انظر [أدوات الزحف](crawling-toolkit.md).

## 10. خدمات المتصفح

`Contracts\BrowserDriver` (دالة واحدة هي `render()`) و`Browser\BrowserResult` و`Browser\PlaywrightBrowserDriver` و
`Transport\Drivers\BrowserTransport` و`Browser\BrowserRequestGuard` و`Browser\NetworkMetadata` و`Browser\NetworkRequestMatcher` و
`Browser\BrowserSessionState` و`Policy\BrowserOptionsResolver` (يحوّل سياسة Scraper إلى عقد خيارات المتصفح بالمللي ثانية).
ويعرض `BrowserFetcher` **الرابط القانوني الذي اجتاز فحوص السياسة**، لا النص الخام المُدخل أبدًا.

## 11. المصدِّرات

`JsonExporter` و`JsonlExporter` و`CsvExporter` و`XmlExporter` تبث العناصر عبر `Export\AtomicFile`: يُنشأ ملف مؤقت حصريًا بصلاحية 0600
ثم يُزامن إلى القرص ثم يُعاد تسميته فوق الهدف، فلا يترك التصدير الفاشل ملفًا مبتورًا.

```php
(new CsvExporter())->export($items, __DIR__ . '/out/books.csv', headers: ['title', 'price'], utf8Bom: true, neutralizeFormulas: true);
```

## 12. الواجهات الأمامية

* **CLI** (`bin/scraper`): الأوامر `fetch` و`crawl` و`version`. يقبل `--transport` أي اسم في السجل.
  ويبقي `crawl --dedupe-db FILE` مجموعة الروابط المزارة في SQLite.
* **خادم MCP** (`bin/scraper-mcp`): الأداتان `fetch_page` و`extract` مع حجب الشبكات الخاصة افتراضيًا.
* **Laravel**: `ScraperServiceProvider` وواجهة `Scrape` والأمر `scraper:fetch`؛ وتُحلّ وسائل النقل عبر `TransportRegistry` من الحاوية.

## 13. الاستثناءات

| الاستثناء | المعنى | يُعاد المحاولة؟ |
|---|---|:-:|
| `ScraperException` | الصنف الأساسي | – |
| `NetworkException` (`TransientFailure`) | مهلة أو قطع أو رفض اتصال أو خطأ قراءة الجسم | نعم |
| `ProxyPoolExhaustedException` | كل البروكسيات في فترة التهدئة | نعم |
| `HttpStatusException` | حالة غير مقبولة | حسب الحالة |
| `UnsafeUrlException` | هدف خاص أو محلي أو غامض | لا |
| `HostNotAllowedException` | مضيف خارج القائمة المسموحة | لا |
| `ResponseTooLargeException` | الجسم أكبر من `maxBodyBytes` | لا |
| `RequestCancelledException` | إلغاء متعمد وليس فشلًا | لا |

---

# كتابة وسيلة نقل خاصة بك

لا تعدّل `Fetcher` ولا `Downloader` ولا `SpiderRunner`. اختر أصغر عقد يناسبك.

## أ. وسيلة نقل متزامنة (تكفي لـ `fetch` والجلسات وSpider المتتابع)

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Policy\HostPolicy;
use HElturkey\WebTrawler\Policy\UrlGuard;
use HElturkey\WebTrawler\Response\FetchResponse;

final class CurlTransport implements TransportInterface
{
    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $started = hrtime(true);
        $limit = $options['max_body_bytes'] ?? null;

        // طبّق السياسة بنفسك: يفحص Downloader الرابط الأول فقط، ووسيلة النقل وحدها ترى التحويلات.
        HostPolicy::assert($url, $options['allowed_hosts'] ?? []);
        if (($options['block_private_networks'] ?? false) === true) {
            UrlGuard::assertPublic($url);
        }

        $headers = [];
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => false,            // تعود التحويلات 3xx؛ وسيلة الإنتاج تكرر هنا وتفحص كل قفزة
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => ($options['verify'] ?? true) !== false,
            CURLOPT_TIMEOUT_MS => (int) (($options['timeout'] ?? 30) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)][] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, $limit): int {
                $body .= $chunk;
                // توقف أثناء القراءة، لا بعد تخزين الجسم كله.
                return $limit !== null && strlen($body) > $limit ? 0 : strlen($chunk);
            },
        ]);

        if (curl_exec($curl) === false) {
            $error = curl_errno($curl) === CURLE_WRITE_ERROR && $limit !== null
                ? ResponseTooLargeException::forLimit($limit, $url)
                : new NetworkException('Request failed: ' . curl_error($curl));
            curl_close($curl);
            throw $error;
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return new FetchResponse($status, $headers, $body, $url, $url, (hrtime(true) - $started) / 1e6);
    }
}

$scraper = Scraper::create(new CurlTransport());
```

قائمة التحقق: افحص كل قفزة تحويل، وأبقِ التحقق من TLS، وافرض حد الحجم أثناء القراءة، وأسقط `Authorization` والكوكيز عند التحويل
لأصل آخر، وأبلغ بالرابط النهائي في `effectiveUrl`، وارمِ `NetworkException` للأخطاء العابرة، وارفض الخيارات التي لا تستطيع تنفيذها بدل تجاهلها.

## ب. وسيلة نقل غير متزامنة (Spider متزامن)

نفّذ `AsyncTransportInterface` واحسم كائنات `Pending` من مدخلاتك ومخرجاتك. يجب أن تعود `sendAsync()` فورًا، وأن تقدّم `tick()` العمل
وتعود حين يُحسم شيء أو لا يبقى شيء قيد التنفيذ أو تمرّ مهلة التلميح.

```php
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface;

final class WorkerPoolTransport implements AsyncTransportInterface
{
    /** @var array<int, Pending> */
    private array $inflight = [];
    private int $next = 1;

    public function __construct(private readonly MyWorkerPool $pool) {}

    public function supportsAsync(): bool { return true; }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $pending = $this->sendAsync($method, $url, $options);
        while (!$pending->isSettled()) {
            $this->tick();
        }
        return $pending->value();            // يعيد رمي الفشل
    }

    public function sendAsync(string $method, string $url, array $options = []): Pending
    {
        $pending = new Pending();
        $id = $this->next++;
        $this->inflight[$id] = $pending;
        $this->pool->submit($id, $method, $url, $options);   // تعود فورًا
        return $pending;
    }

    public function tick(?float $timeoutSeconds = null): void
    {
        foreach ($this->pool->wait($timeoutSeconds) as [$id, $response, $error]) {
            $pending = $this->inflight[$id] ?? null;
            unset($this->inflight[$id]);
            $error === null ? $pending?->resolve($response) : $pending?->reject($error);
        }
    }
}
```

لا تعلن `supportsAsync() === true` وأنت تسلسل الطلبات داخليًا. وأضف `CancellableTransportInterface` (الدالة `cancelAll()`) فقط إن كنت
قادرًا فعلًا على التخلي عن العمل الجاري، وارفض النتائج المتروكة بـ `RequestCancelledException` كي لا تُحسب إخفاقات بروكسي.

## ج. مشغّل متصفح

نفّذ `BrowserDriver::render()` وأعد `BrowserResult`. غلّفه بـ `BrowserTransport` لاستخدامه حيث تُقبل وسيلة نقل، أو مرّره إلى `Scraper::fetchRendered()`.

```php
use HElturkey\WebTrawler\Browser\BrowserResult;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Transport\Drivers\BrowserTransport;

final class PatchrightDriver implements BrowserDriver
{
    public function render(string $url, array $options = []): BrowserResult
    {
        // $options['timeout'] بالمللي ثانية؛ وتطبيق allowed_hosts وblock_private_networks على كل طلب فرعي
        // مسؤوليتك، لأن النواة لا تفحص إلا الرابط الأول والأخير.
        [$html, $finalUrl, $status] = $this->navigate($url, $options);

        return new BrowserResult(html: $html, requestedUrl: $url, finalUrl: $finalUrl, statusCode: $status);
    }
}

$scraper = Scraper::create(new BrowserTransport(new PatchrightDriver(), ['wait_until' => 'networkidle']));
```

يحوّل `BrowserTransport` الثواني إلى مللي ثانية ويرفض الطرق غير GET بدل خفضها.

## د. اجعلها قابلة للاختيار بالاسم

```php
$registry->register('patchright', fn (array $config) => new BrowserTransport(new PatchrightDriver()));
// CLI:     bin/scraper fetch https://example.com --transport patchright   (مرّر السجل إلى Application)
// Laravel: $this->app->afterResolving(TransportRegistry::class, fn ($r) => $r->register('patchright', ...));
```

## هـ. اختبرها

شغّل وسيلة النقل مقابل خادم HTTP محلي (انظر `tests/Support/LocalServer.php` و`tests/Fixtures/router.php`): تحويل إلى عنوان خاص،
وتحويل إلى مضيف آخر، وجسم مجزأ كبير الحجم، واستجابة بطيئة، وتحويل لأصل آخر مع `Authorization`. فحص الأنواع وحده لا يثبت تطبيق السياسة.
