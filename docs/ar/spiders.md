# Spider والزحف القابل للاستئناف

يحول Spider محلل صفحة إلى عملية زحف منظمة. أنشئ صنفًا يرث من `Spider` ونفّذ `parse()`، ثم أعد عناصر وطلبات تالية.

## Spider بسيط

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderItem;
use HElturkey\WebTrawler\Spider\SpiderRequest;

require __DIR__ . '/../vendor/autoload.php';

$spider = new class () extends Spider {
    protected array $startUrls = ['https://example.com/books'];
    protected array $allowedHosts = ['example.com'];
    protected int $maxPages = 100;
    protected int $concurrency = 4;

    public function parse(Page $page, SpiderRequest $request): iterable
    {
        foreach ($page->css('.book-card') as $card) {
            yield new SpiderItem([
                'title' => $card->css('h2')->first()?->text(),
                'url' => $card->css('a')->first()?->attr('href'),
            ]);
        }

        foreach ($page->css('a.next-page') as $link) {
            $href = $link->attr('href');
            if ($href !== null) {
                yield SpiderRequest::get($page->resolve($href));
            }
        }
    }
};

$scraper = Scraper::create();
$result = $scraper->crawl($spider);

foreach ($result->items as $item) {
    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}
```

تعيد `startRequests()` افتراضيًا طلبات GET من `startUrls`. يمكنك إعادة تعريفها لإنشاء طلبات أولية مخصصة بطلب POST أو ترويسات أو بيانات. تعيد `parse(Page, SpiderRequest)` قيمة قابلة للتكرار تحتوي على `SpiderRequest` أو `SpiderItem` أو مصفوفات عناصر.

## إعدادات الزحف

| الإعداد | الافتراضي | المعنى |
|---|---:|---|
| `startUrls` | `[]` | عناوين البداية |
| `allowedHosts` | `[]` | قائمة مضيفين للـ Spider؛ الفراغ لا يفرض قيدًا من هذه القائمة |
| `maxPages` | `1000` | ميزانية جدولة الطلبات التي يفرضها Runner |
| `maxDepth` | `10` | أقصى عمق للعناصر المكتشفة |
| `requestDelayMs` | `0` | الحد الأدنى للفاصل بين بدايات الطلبات للمضيف نفسه |
| `concurrency` | `1` | الطلبات المتزامنة؛ القيم الأكبر من 1 تحتاج Transport غير متزامن |
| `collectItems` | `true` | الاحتفاظ بالعناصر داخل `CrawlResult::$items` |
| `retryPolicy` | `null` | سياسة إعادة محاولة اختيارية للـ Spider |
| `autoThrottle` | `null` | متحكم اختياري في التأخير لكل مضيف |

تتيح `configure()` تغيير مجموعة محددة من إعدادات التشغيل. تُرفض أسماء الإعدادات غير المعروفة.

## أنواع الطلبات

تنشئ `SpiderRequest::get($url, $options)` طلب GET. أما `SpiderRequest::post($url, $data, $options)` فيحفظ بيانات النموذج منفصلة عن `FetchOptions` الموروثة حتى تظل سياسة Scraper الأمنية مطبقة. يشمل مفتاح إزالة التكرار طريقة HTTP وهوية بيانات الطلب؛ لذلك يجب ألا تندمج طلبات POST ذات البيانات المختلفة في طلب واحد.

لا تُحفظ إعدادات البروكسي الصريحة الخاصة بطلب واحد داخل Checkpoint لأنها قد تحتوي على بيانات اعتماد. استخدم ProxyTransport مضبوطًا مسبقًا أو مرجع بروكسي آمنًا يمكن حله عند الاستئناف.

## التزامن وتوافق وسائل النقل

يدعم Guzzle التنزيل غير المتزامن وزحف الطلبات المتزامنة. ويمكن لـ `ProxyTransport` الحفاظ على هذه الإمكانية إذا كانت وسيلة النقل المغلفة تدعمها. أما Rnet وImpity و`RetryingTransport` فهي متزامنة Blocking؛ لذا يجب أن يبقى التزامن `1` عند استخدامها. يستخدم Spider Runner آلية إعادة المحاولة الخاصة به عند العمل مع Transport غير متزامن؛ لا تغلف وسيلة زحف غير متزامنة بـ `RetryingTransport` لأنه Blocking وقد يعطل التزامن أو يكرر المحاولات في طبقتين.

## إعادة المحاولة وAutoThrottle

```php
use HElturkey\WebTrawler\Http\AutoThrottle;
use HElturkey\WebTrawler\Http\RetryPolicy;

$spider->configure([
    'retryPolicy' => new RetryPolicy(
        maxAttempts: 3,
        baseDelayMs: 500,
        maxDelayMs: 30_000,
    ),
    'autoThrottle' => new AutoThrottle(
        targetConcurrency: 1.0,
        startDelayMs: 1000,
        minDelayMs: 250,
        maxDelayMs: 10_000,
    ),
]);
```

تطبق إعادة المحاولة افتراضيًا على الطرق GET وHEAD وOPTIONS، وحالات 408 و425 و429 و500 و502 و503 و504، وأخطاء الشبكة المؤقتة. يتم احترام `Retry-After` عند تفعيله. لا تعد طلبات غير آمنة التكرار ما لم يوفر التطبيق آلية Idempotency.

يضبط AutoThrottle التأخير لكل مضيف وفق زمن الاستجابة والحالة. وهو أداة للالتزام والتهذيب وليس ضمانًا لمعدل نقل معين؛ فقد يوقف جدولة الطلبات. استخدم إعدادات محافظة واحترم شروط الموقع وحدود المعدل.

## Middleware وPipelines

يسمح `DownloaderMiddleware` بتعديل الطلب قبل الجلب، أو فحص/تعديل الصفحة بعد الجلب، أو معالجة الخطأ. ويستقبل `ItemPipeline` كل عنصر ويمكنه إرجاع عنصر معدل أو `null` لإسقاطه. تُستخدم Pipelines للتحقق والتطبيع والحفظ والإخراج المتدفق.

في عمليات الزحف الكبيرة، اضبط `collectItems=false` واحفظ العناصر عبر `ItemPipeline` مثل `JsonlAppendPipeline` لتجنب الاحتفاظ بكل العناصر في الذاكرة.

## استئناف الزحف

استخدم `JsonCrawlStateStore`:

```php
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;

$state = new JsonCrawlStateStore(__DIR__ . '/var/books.state.json');
$result = $scraper->crawl($spider, state: $state);
```

يحفظ Runner نقطة استعادة دوريًا (كل 20 استجابة مكتملة) وعند الخروج. تتضمن الحالة قائمة الانتظار وبيانات إزالة التكرار والعدادات، ولا تتضمن قائمة العناصر الكاملة أو سجل الأخطاء المتراكم. عند الاستئناف يحتوي `items` على عناصر التشغيل الحالي فقط؛ بينما قد تشمل عدادات الطلبات التقدم السابق المحفوظ.

**دلالات التسليم At-least-once وليست Exactly-once.** بعد انهيار مفاجئ قد يُعاد تنفيذ طلب كان قيد التنفيذ. اجعل Pipelines قابلة للتكرار بأمان (مثل Upsert بمفتاح مصدر ثابت)، ولا تنفذ آثارًا جانبية غير قابلة للعكس دون إزالة تكرار. تقبل حالة JSON مجموعة محددة من بيانات الطلب الآمنة. تُرفض الخيارات الإضافية غير المدعومة أو الموارد/Streams أو النصوص الثنائية غير UTF-8 أو الكائنات العشوائية أو البروكسي الصريح لكل طلب بدل إسقاطها بصمت.

احمِ مجلد الحالة وملفها. ينشأ ملف الحالة بصلاحية خاصة `0600`، ويجب استخدام مجلد أب خاص غير متاح للآخرين. لا تحفظ الأسرار أو بيانات الاعتماد الخام ضمن بيانات العناصر.

## الإيقاف المنظم

يمكن للـ CLI التقاط Ctrl-C وSIGTERM وحفظ حالة الاستئناف إذا كان `ext-pcntl` متوفرًا. كما يقبل Runner دالة `shouldStop`:

```php
$result = $scraper->crawl(
    $spider,
    state: $state,
    shouldStop: static fn (): bool => $shutdownRequested,
);
```

افحص `CrawlResult::$interrupted` واستأنف من مخزن الحالة نفسه. الإيقاف المنظم لا يعني أن جميع الطلبات البعيدة اكتملت.

## CrawlResult

تحتوي النتيجة على `items` و`visitedUrls` و`errors` المنقحة و`requestsScheduled` و`requestsCompleted` و`requestsRetried` و`interrupted` و`resumed` و`itemsCount`. عند ضبط `collectItems=false` تكون `items` فارغة، بينما يظل `itemsCount` يعكس عدد العناصر التي أنتجها التشغيل الحالي.
