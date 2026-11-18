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

تنشئ `SpiderRequest::get($url, $options)` طلب GET. أما `SpiderRequest::post($url, $data, $options)` فيحفظ بيانات النموذج منفصلة عن `HElturkey\WebTrawler\Options\FetchOptions` الموروثة حتى تظل سياسة Scraper الأمنية مطبقة. يشمل مفتاح إزالة التكرار طريقة HTTP وهوية بيانات الطلب؛ لذلك يجب ألا تندمج طلبات POST ذات البيانات المختلفة في طلب واحد.

لا تُحفظ إعدادات البروكسي الصريحة الخاصة بطلب واحد داخل Checkpoint لأنها قد تحتوي على بيانات اعتماد. استخدم ProxyTransport مضبوطًا مسبقًا أو مرجع بروكسي آمنًا يمكن حله عند الاستئناف.

## تطبيع الروابط وإزالة تكرار الطلبات والمحتوى

يحتفظ Runner افتراضيًا بمجموعة دقيقة لهويات الطلبات في الذاكرة. تتكون بصمة الطلب من SHA-256 للرابط بعد تطبيعه، وطريقة HTTP بعد تحويلها إلى أحرف كبيرة، وتمثيل حتمي لقيم بيانات الطلب المدعومة. يخفض تطبيع الرابط حالة المخطط والمضيف، ويحذف المنفذ الافتراضي، ويحذف مقاطع المسار الحرفية `.` و`..` ويحوّل المسار الفارغ إلى `/`، دون فك ترميز المقاطع المشفرة بالنسبة المئوية. يحتفظ تطبيع الروابط الموجهة للمتصفح بـ Fragment، بينما يتجاهله SpiderRunner عند تكوين هوية الطلب. لا يعيد ترتيب معاملات Query ولا يحذفها أو يفك ترميزها؛ فقد يكون ترتيبها وترميزها مؤثرًا في سلوك التطبيق. ويحافظ أيضًا على ترتيب عناصر مصفوفة بيانات الطلب، ويضمّن إعدادات `FetchOptions` الصريحة الخاصة بالطلب (بما فيها الترويسات وخيارات النقل) في البصمة لتجنب دمج طلبات قد تختلف دلالتها.

للزحف الأكبر، مرّر مخزن SQLite الاختياري لنقل مجموعة الروابط الدقيقة من مصفوفة PHP إلى قاعدة البيانات:

```php
use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;

$deduplicator = new SQLiteCrawlDeduplicator(
    __DIR__ . '/var/crawl.sqlite',
    expectedUrls: 250_000,
    falsePositiveProbability: 0.005,
);

$result = $scraper->crawl($spider, crawlDeduplicator: $deduplicator);
echo $result->duplicateContentResponses;
```

يتطلب ذلك الامتداد `ext-pdo_sqlite`. المفتاح الأساسي المركب في SQLite هو المرجع الحاسم. أما Bloom Filter المضمّن داخل الحزمة فهو ذاكرة مؤقتة مضغوطة لتسريع حالات عدم الوجود: النتيجة السلبية المؤكدة تذهب مباشرة إلى `INSERT`، والنتيجة الإيجابية المحتملة يجري التحقق منها في SQLite. لذلك لا تؤدي النتيجة الإيجابية الخاطئة في Bloom Filter إلى إسقاط رابط جديد، بل إلى استعلام إضافي فقط. التنفيذ داخلي ويستخدم SHA-256 مع Double Hashing، ولا يحتاج إلى حزمة Bloom خارجية.

يرتبط المخزن بنطاق زحف واحد. عند إيقاف الزحف مع وجود حالة `JsonCrawlStateStore` يُحفظ النطاق نفسه لاستكمال مجموعة الروابط عند الاستئناف. وبعد اكتمال الزحف يُحذف نطاقه، حتى يمكن لعملية زحف لاحقة جلب الروابط نفسها مجددًا. اضبط `expectedUrls` قريبًا من العدد المتوقع للروابط الفريدة في الزحف الواحد؛ تجاوز هذا العدد يؤثر في كفاءة البحث فقط ولا يغيّر صحة إزالة التكرار. إعداد WAL في SQLite مخصص للأقراص المحلية، وليس لأنظمة الملفات الشبكية أو لواجهة زحف موزعة على عدة مضيفين.

الزحف الذي تُقتل عمليته (SIGKILL أو انقطاع الكهرباء أو نفاد الذاكرة) لا يمسح نطاقه أبدًا. شغّل `$deduplicator->purgeOlderThan($seconds)` من مهمة مجدولة لحذف هذه السجلات، واختر نافذة أطول من أطول زحف لديك كي لا يجلب زحف قابل للاستئناف الصفحات من جديد. ومن CLI يفعّل الأمر `bin/scraper crawl spider.php --dedupe-db var/frontier.sqlite` المخزن نفسه.

يسجل المخزن أيضًا بصمة SHA-256 لبايتات جسم الاستجابة كما وصلت إلى Parser، ويربطها بعنوان URL الفعلي بعد التطبيع. يعرض `duplicateContentResponses` عدد الاستجابات التي تطابق جسمًا سبق ظهوره عند عنوان آخر في الزحف نفسه. لا يتم تخطي تحليل الصفحات المتطابقة؛ فقد تحتوي كل صفحة على روابط أو بيانات وصفية خاصة بعنوانها. هذه إزالة تكرار دقيقة للبايتات، وليست مطابقة تقريبية للنص، ولا تخزن أجسام الاستجابات نفسها داخل SQLite.

## للمزيد

`respectRobotsTxt` و`userAgent` و`blockDetector` إعدادات Spider عادية (وتقبلها `configure()` أيضًا). أما `CrawlSpider` و`SitemapSpider` والجلسات والبث
وواجهة SQLite الدفعية فمشروحة في [أدوات الزحف](crawling-toolkit.md). وأضيف إلى `CrawlResult` الحقلان `$robotsBlocked` و`$blockedResponses`.

## التزامن وتوافق وسائل النقل

يدعم Guzzle الافتراضي التنزيل المتزامن عند توفر `ext-curl` وامتلاك الناقل لمعالج curl-multi. أما عميل Guzzle المحقون فيعمل بالتتابع. ويمكن لـ `ProxyTransport` الحفاظ على هذه الإمكانية إذا كانت وسيلة النقل المغلفة تدعمها. أما Rnet وImpity و`RetryingTransport` فهي متزامنة Blocking؛ لذا يجب أن يبقى التزامن `1` عند استخدامها. يستخدم Spider Runner آلية إعادة المحاولة الخاصة به عند العمل مع Transport غير متزامن؛ لا تغلف وسيلة زحف غير متزامنة بـ `RetryingTransport` لأنه Blocking وقد يعطل التزامن أو يكرر المحاولات في طبقتين.

## إعادة المحاولة وAutoThrottle

```php
use HElturkey\WebTrawler\Throttle\AutoThrottle;
use HElturkey\WebTrawler\Retry\RetryPolicy;

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

يحفظ Runner نقطة استعادة دوريًا (كل 20 استجابة مكتملة) وعند الخروج. تتضمن الحالة قائمة الانتظار وبيانات إزالة التكرار والعدادات، ولا تتضمن قائمة العناصر الكاملة أو سجل الأخطاء المتراكم. يقلل SQLite ذاكرة مجموعة بصمات الطلبات الدقيقة، لكن `visitedUrls` والعناصر المجمعة والأخطاء تظل متراكمة وفق عقد `CrawlResult` الحالي. عند الاستئناف يحتوي `items` على عناصر التشغيل الحالي فقط؛ بينما قد تشمل عدادات الطلبات التقدم السابق المحفوظ.

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

تحتوي النتيجة على `items` و`visitedUrls` و`errors` المنقحة و`requestsScheduled` و`requestsCompleted` و`requestsRetried` و`interrupted` و`resumed` و`itemsCount`. وعند تمرير `CrawlDeduplicator`، يوضح `duplicateContentResponses` عدد أجسام الاستجابات المطابقة تمامًا بين عناوين مختلفة. عند ضبط `collectItems=false` تكون `items` فارغة، بينما يظل `itemsCount` يعكس عدد العناصر التي أنتجها التشغيل الحالي.
