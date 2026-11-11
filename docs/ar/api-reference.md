# مرجع الواجهات البرمجية

تلخص هذه الصفحة نقاط الدخول العامة المدعومة. تظل تواقيع PHP في المصدر المثبت هي المرجع النهائي للأنواع الدقيقة والوسائط الاختيارية.

## الواجهة الرئيسية

`HElturkey\WebTrawler\Scraper`

- `Scraper::create(...)`: مصنع يقبل وسيلة HTTP اختيارية ومخزن لقطات ومطابقًا تكيفيًا وخيارات جلب افتراضية وعلامة فرض الأمان وسياسة إعادة المحاولة الافتراضية.
- `fetch(string $url, ?FetchOptions $options = null): Page`
- `get(string $url, ?FetchOptions $options = null): Page`
- `post(string $url, array $data = [], ?FetchOptions $options = null): Page`
- `session(): Session`
- `crawl(Spider $spider, array $middlewares = [], array $pipelines = [], ?JsonCrawlStateStore $state = null, ?callable $shouldStop = null): CrawlResult`
- `fetchRendered(string $url, BrowserDriver $driver, array $options = []): Page`
- `snapshotStore(): SnapshotStore`

## HTTP

- `HElturkey\WebTrawler\Http\FetchOptions`: خيارات الطلب والأمان غير القابلة للتغيير.
- `Fetcher`: تنسيق الطلبات داخليًا ويستخدمه Scraper.
- `GuzzleTransport`: وسيلة HTTP الافتراضية الداعمة للتنفيذ غير المتزامن.
- `RnetTransport`: وسيلة Native اختيارية متزامنة.
- `ImpityTransport`: وسيلة اختيارية متزامنة تعتمد على curl-impersonate.
- `RetryPolicy`: قرارات وإعدادات إعادة المحاولة.
- `RetryingTransport`: غلاف إعادة محاولة متزامن لطلبات النقل الفردية.
- `AutoThrottle`: متحكم في التأخير لكل مضيف داخل Spider.
- `ProxyPool`: اختيار بروكسي مع متابعة الصحة.
- `ProxyTransport`: Decorator يوجه الطلبات عبر مجموعة البروكسي.
- `Session`: جلسة HTTP قائمة على Cookie Jar.

## التحليل

- `HElturkey\WebTrawler\Parsing\Page`: CSS/XPath على مستوى المستند والعنوان والنص وHTML وURL والاستجابة وحل الروابط وبيانات الشبكة واختيار المطابقة التكيفية.
- `Element`: نص العنصر واسمه المتاح وخصائصه والتنقل في DOM والمحددات والسياق.
- `ElementCollection`: مجموعة قابلة للتكرار تتضمن `first` و`last` و`all` و`map` و`each` و`count` و`isEmpty`.

## المطابقة التكيفية

- `ElementSnapshot`: أدلة بنيوية وسياقية قابلة للتسلسل.
- `AdaptiveMatcher`: البحث عن المرشحين وحساب الدرجات.
- `AdaptiveScoringProfile`: أوزان إشارات المطابقة المدعومة.
- `AdaptiveResult` و`AdaptiveMatch` و`AdaptiveSelection`: كائنات النتائج والاختيار.
- `SnapshotStore`: عقد التخزين؛ والمخزنان المدمجان `InMemorySnapshotStore` و`JsonSnapshotStore`.

## الزحف

- `Spider`: الصنف الأساسي لطلبات البداية والتحليل وحدود الزحف والتزامن وإعادة المحاولة والتهذيب.
- `SpiderRequest`: قيمة طلب GET/POST للزحف.
- `SpiderItem`: قيمة العنصر المستخرج.
- `SpiderRunner`: مجدول ومنفذ الزحف.
- `CrawlResult`: العناصر والروابط والأخطاء والعدادات وأعلام الإيقاف والاستئناف.
- `JsonCrawlStateStore`: تخزين حالة الزحف بصيغة JSON.
- `DownloaderMiddleware` و`ItemPipeline`: عقود التوسعة.

## المتصفح

- `BrowserDriver`: عقد تشغيل المتصفح.
- `PlaywrightBrowserDriver`: تنفيذ Chromium الاختياري.
- `BrowserRequestGuard` و`NetworkMetadata` و`NetworkRequestMatcher`: أدوات الحماية ومراقبة الشبكة.
- `BrowserSessionState`: حفظ Cookies وLocal Storage.

## CLI وMCP وLaravel

- ملفات CLI التنفيذية: `vendor/bin/scraper` و`vendor/bin/scraper-mcp`.
- خادم MCP: `HElturkey\WebTrawler\Mcp\McpServer`.
- مزود Laravel: `HElturkey\WebTrawler\Laravel\ScraperServiceProvider`.
- Facade الخاصة بـ Laravel: `HElturkey\WebTrawler\Laravel\Facades\Scrape`.

## التصدير والاستثناءات

أدوات التصدير: `JsonExporter` و`JsonlExporter` و`CsvExporter` و`XmlExporter`. وخط Pipeline المتدفق: `JsonlAppendPipeline`.

تشمل الاستثناءات ذات الصلة `ScraperException` و`NetworkException` و`UnsafeUrlException` و`HostNotAllowedException` و`HttpStatusException` و`ResponseTooLargeException` و`ProxyPoolExhaustedException`.

## إرشادات التوافق

استخدم العقود الموثقة (`HttpTransport` و`AsyncHttpTransport` و`BrowserDriver` و`SnapshotStore` و`DownloaderMiddleware` و`ItemPipeline`) عند إنشاء امتدادات. تجنب الاعتماد على أصناف التنفيذ الداخلية غير المذكورة كنقاط دخول عامة. راجع سجل التغييرات واختبارات التكامل عند الترقية بين الإصدارات.
