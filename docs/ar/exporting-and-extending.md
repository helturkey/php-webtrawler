# التصدير ونقاط التوسعة

## أدوات التصدير المدمجة

تتضمن المكتبة أدوات تصدير JSON وJSONL وCSV وXML:

```php
use HElturkey\WebTrawler\Export\JsonExporter;
use HElturkey\WebTrawler\Export\JsonlExporter;
use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Export\XmlExporter;

$items = [
    ['title' => 'Book A', 'price' => '12.00'],
    ['title' => 'Book B', 'price' => '15.00'],
];

(new JsonExporter())->export($items, __DIR__ . '/out/items.json', pretty: true);
(new JsonlExporter())->export($items, __DIR__ . '/out/items.jsonl');
(new CsvExporter())->export($items, __DIR__ . '/out/items.csv', headers: ['title', 'price']);
(new XmlExporter())->export($items, __DIR__ . '/out/items.xml');
```

راجع توقيع الدالة في النسخة المثبتة عند استخدام خيارات CSV الاختيارية. يدعم CSV تحديد ترتيب الأعمدة، وإضافة BOM اختياري بصيغة UTF-8 لتوافق برامج الجداول، وتحييد الصيغ. إذا لم تحدد الرؤوس، يستنتج CSV الأعمدة من أول صف غير فارغ. عند تغير بنية البيانات، حدد رؤوس الأعمدة وترتيبها بدل الاعتماد على مفاتيح الصفوف المتغيرة.

يستخدم تصدير XML امتداد `ext-xmlwriter`، ويفرغ المخزن بعد كل عنصر، ويضع حدًا لعمق التداخل، وينقح أسماء العناصر، ويتعامل مع Iterator دون تحويله مسبقًا إلى مصفوفة. لا يعني تنقية أسماء XML أو تحييد صيغ CSV اكتمال حماية فقدان البيانات؛ تحقق من بياناتك وصنفها وفق التطبيق.

تقبل أدوات الملفات `iterable` وتكتب عنصرًا واحدًا في كل مرة، ثم تستبدل الملف النهائي ذريًا بعد اكتمال الكتابة بنجاح. يُنشأ الملف المؤقت حصريًا داخل مجلد الوجهة بصلاحيات `0600`، ويرث الملف النهائي الصلاحيات نفسها. عند فشل التصدير يبقى الملف السابق دون تغيير ويُحذف الملف المؤقت. اجعل مجلد الوجهة قابلًا للكتابة بواسطة التطبيق فقط، وتجنب مسارات الويب العامة للبيانات الحساسة. يقلل التدفق الذاكرة الإضافية إلى العنصر الحالي وتمثيله المشفر؛ لكنه لا يقلل الذاكرة التي تستهلكها مصفوفة الإدخال مسبقًا أو عنصر واحد ضخم.

## JsonlAppendPipeline

يكتب `JsonlAppendPipeline` العناصر عند إنتاجها. اجمعه مع `Spider::collectItems=false` في عمليات الزحف الكبيرة لتجنب الاحتفاظ بمجموعة العناصر كاملة في الذاكرة. اجعل معالجة المخرجات قابلة للتكرار بأمان عند استئناف الزحف لأن الطلبات قد تُعاد بعد الانهيار.

## Downloader Middleware

نفّذ `HElturkey\WebTrawler\Contracts\DownloaderMiddleware` للمشاركة في دورة الجلب:

- قبل الجلب: فحص أو تعديل `SpiderRequest`؛
- بعد الجلب: فحص أو تعديل `Page`؛
- عند الخطأ: تسجيل الإخفاق أو تحويله.

يجب ألا تضعف Middleware سياسة الأمان المفروضة على Scraper. لا تسجل بيانات الاعتماد أو الأجسام الخام أو عناوين URL الحساسة دون تنقيح.

## Item Pipelines

نفّذ `HElturkey\WebTrawler\Contracts\ItemPipeline`. تستقبل `process(array $item): ?array` العنصر وتعيده بعد التعديل أو تعيد `null` لإسقاطه. استخدم Pipeline للتحقق من المخطط والتطبيع والحفظ وإزالة التكرار والإخراج المتدفق.

عند استئناف الزحف، فضّل الحفظ القابل للتكرار بأمان باستخدام معرف مصدر ثابت. لا تفترض أن الصفحة ستُسلم مرة واحدة بالضبط.

## وسيلة HTTP مخصصة

نفّذ `HElturkey\WebTrawler\Contracts\Http\TransportInterface` لوسائل النقل المتزامنة و`HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` فقط عند وجود دعم Async حقيقي. يجب أن تحافظ الوسيلة المخصصة على عقد النقل الموثق، بما فيه:

- فحص كل قفزة تحويل مقابل سياسة المضيف والشبكات الخاصة؛
- فرض حد جسم الاستجابة أثناء القراءة وليس بعد تخزينه كاملًا فقط؛
- التحقق من TLS والتعامل مع ترويسات الاعتماد وفق Origin؛
- الإبلاغ عن URL النهائي الفعلي؛
- تمثيل المهلات وأخطاء الاتصال بصورة متسقة؛
- عدم الانتقال إلى اتصال مباشر إذا كان البروكسي إلزاميًا.

أضف اختبارات تكامل خاصة بالمحول باستخدام خادم HTTP محلي قبل نشره. نجاح فحص الواجهة أو الأنواع لا يثبت تطبيق سياسة الشبكة.

## SnapshotStore

نفّذ `SnapshotStore` لحفظ كائنات `ElementSnapshot`. المخزن المدمج في الذاكرة محلي للعملية. ومخزن JSON مخصص للتخزين المحلي لمضيف واحد. تحتاج البيئات الموزعة إلى نظام تخزين يوفّر معاملات أو دلالات تزامن تناسب بنيتها.

## إضافة ناقل أو BrowserDriver خاص بك

لا حاجة لتعديل `Fetcher` أو `Downloader` أو `SpiderRunner`:

- **الناقل:** نفّذ `TransportInterface::send()` وأرجع `HElturkey\WebTrawler\Response\FetchResponse`.
- **التزامن (اختياري):** نفّذ `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface`؛ تعيد `sendAsync()` كائن `Concurrency\Pending` وتدفع `tick()` الإدخال/الإخراج بأي آلية (curl-multi أو Revolt/Amp أو غيرها).
- **المتصفح:** نفّذ `BrowserDriver::render()` وأرجع `BrowserResult`؛ ويحوّله `BrowserTransport` إلى ناقل.
- **التحليل:** `ResponseParser` (الافتراضي `HtmlPageParser` بـ HTML5).

سجّل الاسم في `TransportRegistry` ليظهر في `--transport` وفي إعدادات Laravel. يعالج `SpiderRunner` أي تنزيل ينتهي أولًا ويملأ الخانة الفارغة فورًا.
