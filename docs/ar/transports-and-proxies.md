# وسائل النقل وإعادة المحاولة والبروكسي

## نظرة عامة على وسائل النقل

| وسيلة النقل | الحزمة/متطلبات التشغيل | نمط التنفيذ | ملاحظات |
|---|---|---|---|
| Guzzle | تبعية أساسية؛ يتطلب ext-curl للتزامن | يدعم Async عند استخدام curl-multi داخليًا | الناقل الافتراضي؛ العملاء المحقون يعملون بالتتابع |
| Amp | اختياري: `amphp/http-client` 5 (والـ Proxy يحتاج `amphp/http-tunnel`) | يدعم التنفيذ غير المتزامن | حلقة أحداث Revolt؛ للزحف المتزامن؛ لا Proxy دون `amphp/http-tunnel` |
| php-rnet | امتداد Native اختياري | متزامن Blocking | خيارات بصمة TLS/HTTP شبيهة بالمتصفح؛ التزامن 1 |
| Impity | `dekor/php-impity` وملف `curl-impersonate` متوافق | متزامن Blocking | نقل PSR-18 يعتمد على curl-impersonate؛ التزامن 1 |

اختر وسيلة النقل بوعي. تشابه البصمة لا يضمن قبول الموقع للطلب، كما أن محاكاة TLS/HTTP للمتصفح لا تغني عن المصادقة أو الصلاحيات الصحيحة.

## Guzzle (الافتراضي)

```php
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(transport: new GuzzleTransport());
```

يعمل Guzzle الافتراضي بالتزامن عند توفر `ext-curl` وامتلاك الناقل لمعالج curl-multi. أما `GuzzleTransport` الذي يُنشأ باستخدام `ClientInterface` محقون فيعمل بالتتابع؛ لأن دورة حياة المعالج الخارجي لا يمكن التحكم فيها بأمان من هذا المحول. تمرر إعدادات الأمان من خلال `HElturkey\WebTrawler\Options\FetchOptions` وسلسلة الجلب/النقل.

## php-rnet

ثبّت أو ابنِ امتداد php-rnet المتوافق مع PHP ABI ومعمارية نظام التشغيل المستخدمين في التطبيق. ثم اختر `RnetTransport`:

```php
use HElturkey\WebTrawler\Transport\Drivers\RnetTransport;
use HElturkey\WebTrawler\Scraper;

$transport = new RnetTransport(emulation: 'CHROME_136');
$scraper = Scraper::create(transport: $transport);
```

Rnet متزامن Blocking؛ لذلك استخدم Spider بتزامن `1`. قد يخزن الامتداد الأصلي جسم الاستجابة داخليًا في الذاكرة؛ فلا تعتبر حد الجسم الذي تفرضه المكتبة ضمانًا لحد الذاكرة الداخلية القصوى للامتداد.

## Impity وcurl-impersonate

ثبّت حزمة Composer والملف التنفيذي المتوافق. اضبط مسار الملف قبل إنشاء وسيلة النقل:

```bash
export IMPITY_CURL_IMPERSONATE_PATH=/usr/local/bin/curl-impersonate
```

```php
use HElturkey\WebTrawler\Transport\Drivers\ImpityTransport;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(transport: new ImpityTransport());
```

يجب أن يكون الملف التنفيذي قابلًا للتشغيل ومتوافقًا مع إصدار `dekor/php-impity`. تحقق من الإصدار والمعمارية في بيئة النشر. تثبت اختبارات التكامل إصدار curl-impersonate `2.3.1`؛ ثبّت التركيبة نفسها واختبرها في صورة الإنتاج. Impity متزامن Blocking؛ لذا استخدم تزامن Spider بقيمة `1`.

## وسائل نقل مخصصة

نفّذ `HElturkey\WebTrawler\Contracts\Http\TransportInterface` لوسائل النقل المتزامنة. ولا تنفذ `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` إلا إذا كانت الوسيلة تستطيع فعلًا إرسال الطلبات واستكمالها بشكل غير متزامن. لا تعلن عن دعم Async إذا كانت الطلبات تُنفذ داخليًا بالتتابع.

وسيلة النقل المخصصة جزء من الحد الأمني. يجب أن تتحقق من كل وجهة تحويل، وتحافظ على TLS، وحد حجم الجسم أثناء القراءة، وتزيل ترويسات الاعتماد المرتبطة بالـ Origin عند التحويل بين Origins، وتبلغ عن URL الفعلي، ولا تتجاوز البروكسي الإلزامي بصمت. إذا لم تستطع تنفيذ خيار ما، فارفضه صراحة أو وثّق القيد؛ لا تتجاهله.

أضف اختبارات تكامل خاصة بالمحول باستخدام خادم HTTP محلي قبل تشغيله في الإنتاج. فنجاح فحص الأنواع أو الواجهة لا يثبت وحده تطبيق سياسات الشبكة.

## RetryPolicy

الإعدادات الافتراضية:

- `maxAttempts`: ثلاث محاولات إجمالية، بما فيها المحاولة الأولى؛
- التأخير الأساسي: 500 مللي ثانية؛
- أقصى تأخير: 30 ثانية؛
- المضاعف الأسي: 2؛
- Equal Jitter؛
- الحالات القابلة لإعادة المحاولة: 408 و425 و429 و500 و502 و503 و504؛
- الطرق: GET وHEAD وOPTIONS؛
- احترام `Retry-After` مفعّل.

استخدم `RetryPolicy::none()` أو `maxAttempts: 1` لتعطيل الإعادة. الإعادة مخصصة للأخطاء المؤقتة وليست لتجاوز المصادقة أو سياسات robots أو حدود المعدل أو الأخطاء الدائمة. احترم سياسات الموقع وتجنب إعادة الطلبات غير الآمنة ما لم يكن لدى التطبيق آلية Idempotency.

`RetryingTransport` غلاف متزامن للطلبات الفردية. يمتلك Spider Runner جدولة إعادة المحاولة الخاصة به؛ لذلك لا تغلف وسيلة زحف غير متزامنة بـ `RetryingTransport` لأنه متزامن وقد يلغي التزامن أو يؤدي إلى إعادة المحاولة في طبقتين.

## ProxyPool وProxyTransport

يدعم `ProxyPool` استراتيجيات `round_robin` و`random` و`least_failures`. ويتابع الإخفاقات المتتالية والإجمالية والنجاحات، ويوقف البروكسي غير السليم مؤقتًا، ثم يسمح بمسبار Half-open واحد بعد انتهاء فترة التوقف.

```php
use HElturkey\WebTrawler\Proxy\ProxyPool;
use HElturkey\WebTrawler\Proxy\ProxyTransport;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$pool = new ProxyPool(
    proxies: [
        getenv('SCRAPER_PROXY_ONE'),
        getenv('SCRAPER_PROXY_TWO'),
    ],
    strategy: ProxyPool::ROUND_ROBIN,
    maxConsecutiveFailures: 3,
    cooldownSeconds: 60,
);

$transport = new ProxyTransport(
    inner: new GuzzleTransport(),
    pool: $pool,
    allowDirectFallback: false,
);
```

المجموعة مسار ملزم افتراضيًا: يُرفض الطلب الذي يحمل الخيار `proxy` الخاص به بـ `InvalidArgumentException` بدل أن يتجاوز المجموعة
بصمت. مرّر `allowExplicitProxyOverride: true` فقط حين تثق في كل مستدعٍ ليختار بروكسيه بنفسه.

احذف القيم الفارغة أو غير المضبوطة من متغيرات البيئة قبل إنشاء المجموعة. إذا لم يتوفر بروكسي صالح، يرمي النظام `ProxyPoolExhaustedException` ما لم يُفعّل الاتصال المباشر صراحة. اتركه معطلًا إذا كان المرور عبر البروكسي إلزاميًا. تخفي الإحصاءات بيانات الاعتماد ولا تعرض أسرار Query أو Path.

يضبط مزود خدمة Laravel مجموعة البروكسي لطلبات HTTP وSpider. ولا تطبق المجموعة تلقائيًا على Playwright؛ إذ يحتاج المتصفح إلى إعداد Proxy مستقل أو توجيه شبكي إلزامي.

## قاعدة توافق وسائل النقل

للزحف المتزامن استخدم Guzzle الافتراضي مع `ext-curl` أو Amp أو وسيلة نقل أخرى تم اختبار دعمها الحقيقي للتنفيذ غير المتزامن. عميل Guzzle المحقون يعمل بالتتابع. Rnet وImpity متزامنتان. اختبر وسيلة النقل وتركيبة البروكسي في اختبارات التكامل قبل زيادة التزامن.


الإلغاء قدرة اختيارية. نفّذ `HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface` (الممتد من `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface`) فقط إذا كانت وسيلة النقل قادرة على إلغاء الطلبات قيد التنفيذ. يمكن للناقل الذي لا يدعم الإلغاء تنفيذ `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` وحده. يُمثّل الإلغاء المتعمد بالاستثناء `RequestCancelledException`، ولا ينبغي احتسابه فشلًا شبكيًا أو فشلًا في البروكسي.
