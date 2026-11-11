# الجلب والجلسات وتحليل الصفحات

## واجهة الجلب

أنشئ كائن Scraper واستخدم إحدى الدوال:

```php
$page = $scraper->fetch($url, $options); // طلب عام، GET افتراضيًا
$page = $scraper->get($url, $options);
$page = $scraper->post($url, $data, $options);
```

تقبل `Scraper::create()` وسيطًا اختياريًا من نوع `HttpTransport`، ومخزن لقطات `SnapshotStore`، ومطابقًا تكيفيًا `AdaptiveMatcher`، وخيارات افتراضية `FetchOptions`، وعلامة `enforceDefaultSecurity`، وسياسة إعادة محاولة افتراضية `RetryPolicy`. يُفضّل استخدام الوسائط المسماة لتكون إعدادات السياسة واضحة.

تعيد دوال الجلب كائن `HElturkey\WebTrawler\Parsing\Page`. قد تظهر أخطاء HTTP ضمن استثناءات النقل أو بيانات الاستجابة بحسب المسار؛ أما Spider فيقبل حالات 2xx فقط افتراضيًا.

## خيارات FetchOptions

`FetchOptions` كائن غير قابل للتغيير. استخدم معاملات المُنشئ أو الدالتين `withAllowedHosts()` و`withExtraOption()` لإنشاء نسخة معدلة.

| الخيار | الافتراضي | المعنى |
|---|---:|---|
| `headers` | `[]` | ترويسات الطلب؛ القيم نصوص أو مصفوفات نصية |
| `timeout` | `30.0` | مهلة الطلب الكلية بالثواني؛ القيمة `0` تعني بلا حد |
| `connectTimeout` | `10.0` | مهلة إنشاء الاتصال بالثواني؛ القيمة `0` تعني بلا حد |
| `proxy` | `null` | عنوان بروكسي أو مصفوفة إعدادات مدعومة |
| `verifyTls` | `true` | التحقق من شهادات TLS |
| `allowRedirects` | `true` | اتباع التحويلات ضمن سياسة التحويلات |
| `maxRedirects` | `10` | الحد الأقصى لقفزات التحويل |
| `extra` | `[]` | خيارات متقدمة شبيهة بخيارات Guzzle |
| `maxBodyBytes` | `20 MiB` | إيقاف الاستجابة إذا تجاوز الجسم الحد؛ `null` يعطل الحماية |
| `blockPrivateNetworks` | `false` | رفض وجهات loopback والشبكات الخاصة وlink-local |
| `encoding` | `null` | ترميز صريح للجسم؛ وإلا يتم الفحص وفق BOM ثم HTTP ثم HTML meta ثم UTF-8 |
| `allowedHosts` | `[]` | المضيفون المسموحون ونطاقاتهم الفرعية؛ الفراغ يعني عدم فرض قائمة مضيفين |

تؤدي الاستجابة التي تتجاوز `maxBodyBytes` إلى `ResponseTooLargeException`. احتفظ بحد محدود عند التعامل مع وجهات غير موثوقة.

## واجهة التحليل

توفر `Page` الدوال التالية:

- `css(string $selector): ElementCollection`
- `xpath(string $expression): ElementCollection`
- `title(): ?string`
- `text(): string`
- `html(): string`
- `url(): string`، وهو عنوان الصفحة الفعلي بعد التحويلات.
- `resolve(string $url): string` لحل الروابط النسبية نسبةً إلى عنوان الصفحة.
- `response(): ?FetchResponse`
- `networkRequests()` و`apiResponses()` و`failedRequests()` لبيانات شبكة المتصفح عند توفرها.
- `remember(string $key, Element $element)` و`adaptive(string $key, ...)` للمطابقة التكيفية.

يوفر `Element` الدوال `text()` و`ownText()` و`accessibleName()` و`attr()` و`hasAttr()` و`tag()` و`attributes()` و`css()` و`xpath()` و`parent()` و`children()` و`siblings()` وأدوات سياق الآباء والأشقاء.

استخدم الوصول الآمن عند احتمال غياب العنصر:

```php
$price = $page->css('.price')->first()?->text();
```

تعامل مع النصوص والخصائص باعتبارها بيانات غير موثوقة. اهرب المخرجات بحسب السياق المستهدف (HTML أو SQL أو Shell أو السجلات أو CSV)؛ فالتحليل لا يجعل المحتوى المستخرج آمنًا تلقائيًا.

## التعامل مع الترميز

عند عدم تحديد `encoding`، يفحص فك ترميز HTML علامة BOM ثم ترميز HTTP في `Content-Type` ثم تصريحات HTML `<meta>`، ثم يستخدم UTF-8 إذا كانت البيانات صالحة. حدّد `encoding` صراحة فقط إذا كنت تعرف ترميز المصدر؛ فالقيمة الصريحة تتقدم على آلية الكشف المعتادة.

## جلسات Cookies

تنشئ `$scraper->session()` غلاف جلسة يحتوي Cookie Jar:

```php
$session = $scraper->session();
$loginPage = $session->get('https://example.com/login');
$accountPage = $session->post('https://example.com/login', [
    'username' => $username,
    'password' => $password,
]);
$cookies = $session->cookies();
```

تحتفظ الجلسة بملفات Cookies للطلبات التي تمر عبر كائن الجلسة نفسه. لكنها لا تعني حفظ local storage أو تبويبات المتصفح أو حالة تسجيل الدخول على الخادم.

## حالات الاستجابة والأخطاء

افحص `Page::response()` عندما تتوفر بيانات الاستجابة. تعامل صراحة مع استثناءات الشبكة وعناوين URL غير الآمنة وأخطاء حالة HTTP وتجاوز حجم الاستجابة. لا تفترض أن كل عنوان يعيد HTML أو حالة 2xx.
