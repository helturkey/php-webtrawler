# أدوات الزحف: robots.txt والقواعد وخرائط الموقع والجلسات والبث وMarkdown

تغطي هذه الصفحة ميزات الزحف عالية المستوى. كلها اختيارية؛ فالـ `Spider` العادي يعمل تمامًا كما كان.

## robots.txt

```php
final class BooksSpider extends Spider
{
    protected array $startUrls = ['https://books.example/'];
    protected bool $respectRobotsTxt = true;
    protected ?string $userAgent = 'BooksBot/1.0 (+https://my.site/bot)';
    // ...
}
```

مع `respectRobotsTxt` يجلب المشغّل `/robots.txt` مرة واحدة لكل أصل (المخطط + المضيف + المنفذ)، ويخزنه 24 ساعة،
ويفحص كل طلب قبل تنزيله.

* يتبع `Allow`/`Disallow` معيار RFC 9309: القاعدة الأطول مطابقةً تفوز، و`Allow` تفوز عند التعادل، و`*` رمز بدل، و`$` في النهاية تثبّت نهاية المسار.
  المطابقة تجري بخوارزمية خطية لا بتعبيرات نمطية، فلا يستطيع ملف عدائي إحداث تراجع كارثي. يُقرأ 500 كيبيبايت و10000 قاعدة على الأكثر.
* يرفع `Crawl-delay` التأخير لكل مضيف (ولا يخفّض تأخيرًا ضبطته أنت).
* تُختار المجموعة بـ**رمز المنتج** في `$userAgent` (`BooksBot`)؛ وبلا `$userAgent` يُستخدم الرمز الافتراضي `HElturkey-WebTrawler`.
  وتُرسل السلسلة نفسها في ترويسة `User-Agent`، فلا يختلف ما تعلنه عمّا ترسله.
* التوفر يتبع المعيار: `2xx` يُحلَّل، و`4xx` تعني "لا قيود"، وخطأ الخادم أو تعذّر الوصول يعني "افترض أن كل شيء ممنوع"
  (يُخزَّن 5 دقائق فقط ليتعافى الزحف).
* مخالفات سياسة الروابط (عنوان خاص أو مضيف غير مسموح) **لا** تُخفى خلف المنع: تظهر بسببها الحقيقي.
* تُحسب الطلبات المتجاوزة في `CrawlResult::$robotsBlocked` وتُدرج في `$errors` بالصنف `RobotsDisallowed`.

الجلب متزامن (مرة لكل أصل في اليوم) ويمر عبر سياسة الأمان نفسها. وللاستعمال المنفرد:
`RobotsTxt::parse($text)->isAllowed('MyBot', '/path?x=1')` و`RobotsPolicy`.

من سطر الأوامر: `scraper crawl spider.php --robots --user-agent "BooksBot/1.0"`.

## LinkExtractor وCrawlSpider وRule

يعيد `LinkExtractor` روابط http(s) المطلقة الفريدة بلا أجزاء `#` والتي تجتاز مرشّحاتك:

```php
$extractor = new LinkExtractor(
    allow: ['#/product/\d+#'],       // يجب أن يطابق واحد على الأقل (فارغ = أي رابط)
    deny: ['#/product/\d+/print#'],
    allowDomains: ['shop.example'],   // المضيفات ونطاقاتها الفرعية
    restrictCss: 'main',              // ابحث داخل <main> فقط
    skipNofollow: true,
);
$links = $extractor->extract($page);  // list<string>
$extractor->matches('https://shop.example/product/1'); // اختبر رابطًا دون صفحة
```

تُتخطى افتراضيًا الملفات الثابتة والوسائط والأرشيفات (`jpg` و`pdf` و`zip` و`css` و`js`...)؛ مرّر `denyExtensions: []` لإبقائها.
وتُتحقق الأنماط عند إنشاء المستخرج.

يحوّل `CrawlSpider` المستخرجات إلى زحف:

```php
final class ShopSpider extends CrawlSpider
{
    protected array $startUrls = ['https://shop.example/'];
    protected array $allowedHosts = ['shop.example'];

    protected function rules(): array
    {
        return [
            new Rule(new LinkExtractor(allow: ['#/category/#'])),                    // تنقّل
            new Rule(new LinkExtractor(allow: ['#/product/\d+#']), 'parseProduct'),  // استخراج
        ];
    }

    protected function parseProduct(Page $page, SpiderRequest $request): iterable
    {
        yield ['url' => $page->url(), 'name' => $page->css('h1')->first()?->text()];
    }
}
```

الدلالات (نفسها في `CrawlSpider` من Scrapy): تُفحص كل صفحة بمستخرج كل قاعدة؛ وتُوجَّه الاستجابة بحسب رابطها إلى **أول قاعدة يقبلها مستخرجها**؛
فتعالجها دالة تلك القاعدة، وإن كانت القاعدة لا تتبع (`follow` افتراضيًا *صحيح فقط حين لا توجد دالة*) فلا تُفحص روابط الصفحة نفسها.
وروابط البداية تذهب إلى `parseStart()` وتُفحص دائمًا. التوجيه يعتمد على الرابط وحده، فلا تحتاج نقاط الحفظ والاستئناف إلى حالة إضافية.
ويجب أن تكون الدوال public أو protected.

## SitemapSpider

```php
final class BlogSpider extends SitemapSpider
{
    protected array $sitemapUrls = ['https://blog.example/sitemap.xml'];
    protected array $sitemapRules = ['#/posts/#' => 'parsePost'];   // النمط => الدالة
    protected array $sitemapFollow = ['#posts-\d+\.xml$#'];          // أي خرائط فرعية في الفهرس تُفتح

    protected function parsePost(Page $page, SpiderRequest $request): iterable { yield [...]; }
}
```

تُتبع فهارس الخرائط، وتُفك ضغط `.xml.gz` (بحد أقصى 50 ميبيبايت)، ويُقرأ 50000 مدخل على الأكثر لكل ملف. ولا تُجلب إلا الروابط المطابقة لقاعدة.
ولا يجوز لخريطة أن تسرد إلا روابط **مضيفها نفسه** (كما يشترط البروتوكول)؛ اضبط `protected bool $allowCrossHostEntries = true;` لتخفيف ذلك.
ويرفض محلل XML تعريفات الكيانات ولا يلمس الشبكة (`SitemapParser`).

## الاستجابات المحجوبة (كشف لا تحايل)

```php
protected ?BlockDetector $blockDetector = null;   // اضبطه على BlockDetector::default() للتفعيل

public function onBlocked(Page $page, SpiderRequest $request, BlockSignal $signal): iterable
{
    // $signal->vendor: cloudflare | akamai | datadome | perimeterx | imperva | captcha | rate-limit | generic
    if ($request->session === null) {
        yield $request->viaSession('browser');   // جرّب الرابط نفسه عبر متصفح حقيقي
    }
}
```

يتعرف `BlockDetector` على صفحات التحدي ورفض جدران الحماية وتحديد المعدل (ترويسة `cf-mitigated` وحالة 429 وعلامات معروفة في صفحات 401/403/406/503).
وفي استجابات 2xx لا تُحتسب إلا صفحة Cloudflare الوسيطة نفسها ("Just a moment..." و`_cf_chl_opt`)، فلا يُظن نموذج تسجيل دخول يضمّن أداة CAPTCHA حجبًا.
والاستجابة المحجوبة **لا تُحلَّل** (فصفحة التحدي تنتج عناصر فارغة أو خاطئة)؛ تُحسب في `CrawlResult::$blockedResponses` وتُدرج في `$errors` بالصنف `BlockedResponse`
وتُمرَّر إلى `onBlocked()`.

المكتبة **تكشف** الحجب، ولا **تحل** اختبارات CAPTCHA أو Turnstile ولا تحاول هزيمة ضوابط الوصول في الموقع. وما تقدمه بدلًا من ذلك: بصمات TLS/HTTP2
شبيهة بالمتصفح عبر وسيلتي Rnet وImpity الاختياريتين (الخيار `--emulation`)، ووتيرة مهذبة (`AutoThrottle` و`Crawl-delay`)، ومجموعات بروكسي،
وآلية الجلسات أدناه لإعادة جلب صفحة يحق لك قراءتها عبر متصفح حقيقي.

## الجلسات: HTTP ومتصفح في Spider واحد

```php
$scraper = Scraper::create(new GuzzleTransport());
$result = $scraper->crawl(
    $spider,
    sessions: ['browser' => new BrowserTransport(new PlaywrightBrowserDriver(), ['wait_until' => 'networkidle'])],
);
```

يختار الطلب جلسة بـ `SpiderRequest::get($url)->viaSession('browser')`. تشارك الجلسات سياسة أمان Scraper وإعادة المحاولة الافتراضية والمحلل.
وتُنزَّل طلبات الجلسات واحدًا واحدًا حتى في الزحف المتزامن (فالمتصفح لا تقوده حلقة الأحداث المشتركة)، فوجّه إليها الصفحات التي تحتاجها فقط.
واسم الجلسة جزء من هوية الطلب: فلا يُرمى الطلب المصعَّد على أنه تكرار للأصلي، ويُحفظ الاسم في نقاط الحفظ.

## البث

```php
$stream = $scraper->stream($spider, pipelines: [new JsonlAppendPipeline(__DIR__ . '/out.jsonl')]);
foreach ($stream as $item) {
    handle($item);                 // يصل فور تحليل الصفحة
}
$result = $stream->getReturn();    // CrawlResult (قائمة العناصر فيه فارغة: لا شيء يُخزَّن)
```

تبقى الذاكرة ثابتة مهما كبر الزحف. وتمر العناصر عبر Pipelines أولًا. و`break` يوقف الزحف وينفذ التنظيف المعتاد. يعمل الزحف داخليًا في Fiber؛
فلا توقفه بنفسك، ويُفضَّل مع `stream()` وسيلة نقل غير Revolt (وسيلة Amp غير مشمولة باختبارات البث).

## الواجهة الأمامية الكبيرة: كتابة SQLite دفعيًا

ينفذ `SQLiteCrawlDeduplicator` الواجهة `BatchCrawlDeduplicator`. فحين تُخرج صفحة روابط كثيرة يسجلها المشغّل كلها في **معاملة واحدة** بدل كتابة لكل رابط.
السلوك لم يتغير (الصفحات نفسها والترتيب نفسه وميزانية الصفحات نفسها)؛ ينقص عدد الـ commit فقط. ويقيس `benchmarks/frontier.php` الاستراتيجيات الثلاث على جهازك؛
ولم تُرفق أرقام لأنها تعتمد على القرص. فعّله بـ `--dedupe-db frontier.sqlite` أو `crawl(crawlDeduplicator: new SQLiteCrawlDeduplicator($path))`.

## ذاكرة التطوير المؤقتة

```bash
bin/scraper fetch https://example.com --cache-dir .cache --markdown          # ينزّل
bin/scraper fetch https://example.com --cache-dir .cache --markdown          # من القرص
bin/scraper fetch https://example.com --cache-dir .cache --offline           # لا يلمس الشبكة أبدًا
```

يخزّن `CachingTransport` استجابات GET على القرص (المجلد 0700 والملفات 0600 والكتابة ذرية) لتعيد كتابة `parse()` وتشغيله على الصفحات نفسها فورًا، أو لتعيد
تشغيل خلل دون اتصال. يتجاهل `Cache-Control` ويخزن الحالات الأقل من 500 عدا 429، ويدعم مدة صلاحية اختيارية، وهو متزامن فقط (بلا تزامن طلبات)،
و**للتطوير فقط**: فقد تحوي الملفات المخزنة كوكيز. ويحوّل `--offline` الغياب في الذاكرة إلى خطأ. وبالكود:

```php
$scraper = Scraper::create(new CachingTransport(new GuzzleTransport(), __DIR__ . '/.cache', ttlSeconds: 3600));
```

## Markdown لأنظمة RAG والنماذج اللغوية

```php
$page->markdown();                          // الجسم
$page->markdown('article.post');            // جزء من الصفحة
$page->markdown(mainContent: true);         // <main>/<article> دون nav/footer/aside
$page->css('.card')->first()->markdown();   // عنصر واحد
```

CommonMark مع جداول GitHub: العناوين والتوكيد والروابط (مطلقة) والصور والقوائم المتداخلة والمرقمة والشيفرة المسوّرة (مع اللغة) والاقتباسات والجداول.
تُحذف السكربتات والأنماط والإطارات وعناصر النماذج، وكذلك المحتوى المخفي عن القارئ (`hidden` و`aria-hidden="true"` و`display:none`/`visibility:hidden` المضمنة)؛
ولا يبقى من وجهات الروابط إلا `http` و`https` و`mailto` و`tel`، وتُزال روابط `data:`/`javascript:`. وهذا جهد معقول لا أكثر:
فالنص المخفي بورقة أنماط خارجية لا يمكن كشفه، فعامل الصفحات المجلوبة على أنها مدخلات غير موثوقة لأي نموذج. والمسافات داخل كتل الشيفرة تُحفظ كما هي.
من سطر الأوامر: `fetch URL --markdown [--select CSS] [--main-content]`؛ ومن MCP: الأداة `fetch_page` مع `"format": "markdown"`.

## الصدفة التفاعلية

```text
$ bin/scraper shell https://example.com
> css h1
[0] <h1> Example Domain
> attr href a
https://www.iana.org/domains/example
> links
> markdown
> quit
```

الأوامر: `fetch` و`url` و`title` و`text` و`css` و`xpath` و`count` و`attr <name> <selector>` و`links` و`markdown [selector]` و`help` و`quit`.
هي مجموعة أوامر ثابتة تُقرأ سطرًا سطرًا، **وليست مفسّر PHP**: لا يُنفَّذ شيء مما تكتبه، وكل تنزيل يمر بسياسة الأمان المعتادة (`--block-private` و`--max-bytes`...).
استخدمها لإيجاد المحددات ثم انسخها إلى Spider.

## Docker

```bash
docker build -f docker/Dockerfile -t webtrawler .
docker run --rm webtrawler fetch https://example.com --block-private --markdown
docker run --rm -v "$PWD/spiders:/spiders:ro" -v "$PWD/out:/out" webtrawler \
  crawl /spiders/books.php --format jsonl --output /out/books.jsonl --state /out/books.state.json --robots
```

يوفر ملف `docker/Dockerfile` هدف الإنتاج `runtime` وهدفًا موحدًا للاختبارات `ci`. نفّذ `composer test-docker` لبناء صورة الإنتاج وتشغيل فحوص Smoke الخاصة بها، ثم بناء صورة CI واحدة بمعمارية AMD64 وتشغيل اختبارات المتصفح وImpity وphp-rnet بالتتابع داخل حاوية معزولة واحدة. صورة CI مخصصة للتحقق فقط ولا ينبغي نشرها.

تحتوي صورة الإنتاج على اعتماديات الإنتاج فقط، وتعمل بمستخدم غير مميز، وتكتب مخرجات الزحف تحت `/out`، وتعطّل assertions، وتحدّ ذاكرة PHP بـ 512 ميغابايت. راجع [دليل تشغيل Docker والنشر والاختبارات](docker.md) لتفاصيل البناء والمجلدات والأمان واستكشاف الأخطاء.
