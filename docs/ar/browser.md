# تشغيل المتصفح

تشغيل المتصفح اختياري. لا تحتاج عمليات HTTP العادية وتحليل HTML إلى Node.js أو Playwright أو Chromium.

## تثبيت تكامل المتصفح

ثبّت حزمة PHP Playwright في التطبيق (أو ضمن إعداد التطوير الموثق للمشروع)، وثبّت Node.js بإصدار 20 أو أحدث، ثم نزّل Chromium باستخدام مثبت Playwright:

```bash
composer require playwright-php/playwright:^1.6
vendor/bin/playwright-install --with-deps chromium
```

تستخدم صورة Browser CI الخاصة بالمشروع PHP 8.4 وNode 24 وnpm وChromium. ينبغي تثبيت واختبار إصدارات خادم Playwright والمتصفح معًا؛ ولا تفترض توافق Chromium المثبت من النظام دون اختبار.

## عرض الصفحة

```php
use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$options = new FetchOptions(
    timeout: 30,
    blockPrivateNetworks: true,
    allowedHosts: ['quotes.example.com'],
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$browser = new PlaywrightBrowserDriver();
$page = $scraper->fetchRendered('https://quotes.example.com/', $browser, [
    'wait_until' => 'domcontentloaded',
    'wait_for_selector' => '.quote',
]);

$quotes = $page->css('.quote')->map(static fn ($quote): array => [
    'text' => $quote->css('.text')->first()?->text(),
    'author' => $quote->css('.author')->first()?->text(),
]);
```

يشغّل Driver متصفحًا لعملية العرض. لا تفترض وجود عملية Browser طويلة العمر أو تبويبات باقية بين الاستدعاءات.

## خيارات المتصفح

يقبل Driver مصفوفة خيارات، ومن أهم مفاتيحها:

| المفتاح | السلوك الافتراضي |
|---|---|
| `headless` | `true` |
| `args` | معاملات إضافية لـ Chromium؛ راجعها لأن بعض Flags قد تضعف العزل |
| `slow_mo` | تأخير اختياري بين إجراءات المتصفح |
| `context` | خيارات سياق Playwright |
| `verify_tls` | يرث سياسة Scraper؛ اتركه مفعّلًا |
| `proxy` | إعداد Proxy خاص بالمتصفح |
| `headers` | ترويسات إضافية للسياق/التنقل |
| `max_network_requests` | الافتراضي 500، والحد بين 1 و10,000 |
| `capture_request_bodies` | معطل افتراضيًا |
| حدود أجسام الطلب | 256 KiB لكل طلب و1 MiB إجماليًا افتراضيًا؛ وتوجد حدود قصوى للقيم المضبوطة |
| التقاط الاستجابات | يمكن تحديد XHR/Fetch بواسطة `network_capture_patterns`، مع فرض حدود لكل جسم ومجموع الأجسام |
| مهلة التنقل | ترث مهلة Scraper وتحوّل الثواني إلى مللي ثانية لـ Playwright |
| `wait_until` | `load` افتراضيًا؛ يمكن اختيار `domcontentloaded` لتقليل الانتظار |
| `wait_for_selector` | محدد CSS اختياري للانتظار |
| `selector_timeout` | الافتراضي 10,000 مللي ثانية |
| `delay_ms` | تأخير اختياري بعد التنقل، بحد أقصى 30 ثانية |

يتم تقييد التقاط الشبكة بحدود لكل عنصر وحدود إجمالية. هذه حدود للتخزين/الالتقاط وليست ضمانًا قاطعًا بأن Chromium لن يخصص ذاكرة أكبر داخليًا. لا تفعّل التقاط الأجسام إلا عند الحاجة.

## جلسات المتصفح

يمكن حفظ Cookies وLocal Storage بين تشغيلات المتصفح. لكن لا تحفظ حالة JavaScript أو Session Storage أو التبويبات المفتوحة أو كائنات Page أو عملية Browser قائمة.

تعامل مع حالة المتصفح باعتبارها بيانات اعتماد. يجب أن يكون مجلد الحالة الافتراضي خاصًا (`0700`) ومملوكًا للمستخدم الحالي، وتنشأ ملفات الحالة بصلاحيات مقيدة (`0600`). تعتمد فحوصات الملكية على `ext-posix`. يوجد خيار صريح للسماح بمجلد مشترك في بيئات مضبوطة، لكن تجنبه عندما تحتوي الجلسات على بيانات مستخدمين. لا تضف ملفات الحالة إلى Git أو سياق بناء الحاويات.

## SSRF في المتصفح: قيد مهم

لا يعد توجيه Playwright للطلبات حدًا كاملًا للحماية من SSRF:

- لا يضمن نظام Routing فحص كل وجهة في كل قفزة تحويل؛ فقد يتبع Chromium التحويل قبل أن يتمكن التطبيق من رفض نتيجة العرض.
- يمكن لـ Service Workers تجاوز Routing؛ ويعطلها Driver عند تفعيل حارس Routing، لكن لا ينبغي اعتبار ذلك دفاعًا وحيدًا.
- عنوان الخادم الذي تتم ملاحظته للتنقل الرئيسي لا يمثل سجلًا كاملًا لعناوين IP الفعلية لكل الموارد الفرعية.
- يتطلب DNS Rebinding وسلوك الشبكة الداخلي للمتصفح ضوابط أدنى من مستوى التطبيق.

عند فتح وجهات غير موثوقة، شغّل Chromium في Container أو VM أو Network Namespace معزول أو خلف Egress Proxy/Firewall إلزامي. امنع الاتصالات المباشرة إلى loopback وprivate وlink-local وmulticast وreserved ونطاقات metadata السحابية. امنع المتصفح من تجاوز هذا القيد. تستخدم اختبارات Chromium المعزولة في CI الخيار `--network none`؛ وهذا اختبار لذلك السياق، ولا يعني أن تشغيل المتصفح المحلي العادي معزول عن الشبكة.

## إرشادات تشغيل المتصفح

- استخدم `allowed_hosts` و`block_private_networks` كفحوص تطبيقية إضافية.
- لا تسمح للمستخدمين بتمرير معاملات Chromium اعتباطيًا.
- حدّد تزامن المتصفح على مستوى Worker أو العملية.
- حدّث صورة المتصفح وأعد بناءها دوريًا.
- لا تعرض أجسام الطلبات والاستجابات الخام لمستخدمين غير موثوقين دون تنقية وحدود حجم.
- تعامل مع DOM المعروض وبيانات API الملتقطة على أنها محتوى بعيد غير موثوق.
