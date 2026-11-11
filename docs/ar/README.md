# PHP WebTrawler

مكتبة PHP لجلب صفحات الويب وتحليل DOM ومطابقة العناصر بآلية تكيفية، وتنفيذ الزحف المتزامن، وتشغيل المتصفح، وتصدير البيانات، مع تكاملات اختيارية مع Laravel وMCP.

تفصل المكتبة بين جلب HTTP العادي وتشغيل Chromium. يستخدم المشروع Guzzle افتراضيًا، وهو النقل الأساسي الداعم للتنفيذ غير المتزامن. أما Rnet وImpity فهما وسيلتا نقل اختياريتان ولهما متطلبات تشغيل مستقلة. تشغيل المتصفح اختياري ويعتمد على Playwright.

## فهرس الوثائق

- [البدء السريع](getting-started.md)
- [الجلب والجلسات وتحليل الصفحات](fetching-and-parsing.md)
- [خيارات HTTP والأمان](http-and-security.md)
- [المحددات التكيفية](adaptive-selectors.md)
- [Spider والزحف القابل للاستئناف](spiders.md)
- [وسائل النقل وإعادة المحاولة والبروكسي](transports-and-proxies.md)
- [تشغيل المتصفح](browser.md)
- [CLI وخادم MCP](cli-and-mcp.md)
- [تكامل Laravel](laravel.md)
- [التصدير ونقاط التوسعة](exporting-and-extending.md)
- [الاختبارات وCI والإصدار](testing-and-release.md)
- [مرجع الواجهات البرمجية](api-reference.md)

## مثال سريع

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = new FetchOptions(
    timeout: 20,
    maxBodyBytes: 10 * 1024 * 1024,
    blockPrivateNetworks: true,
    allowedHosts: ['example.com'],
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$page = $scraper->get('https://example.com/');

printf("Title: %s\n", $page->title() ?? '(no title)');
foreach ($page->css('h1')->all() as $heading) {
    echo $heading->text() . PHP_EOL;
}
```

## الحد الأدنى الأمني

- تعامل مع HTML والنصوص والروابط والخصائص وبيانات API المستخرجة على أنها بيانات غير موثوقة.
- عند استقبال عناوين URL من المستخدمين، فعّل منع الشبكات الخاصة واستخدم قائمة مضيفين مسموح بها بحسب التطبيق.
- اترك التحقق من TLS مفعّلًا. لا تعطل فحص التحويلات أو حدود حجم الاستجابة لمجرد تجاوز مشكلة في موقع ما دون فهم أثر ذلك.
- فحوصات URL على مستوى التطبيق ليست بديلًا عن تقييد الخروج الشبكي؛ فهي لا تمنع وحدها كل حالات DNS Rebinding أو تغيّر DNS بعد الفحص.
- توجيه Playwright للطلبات وسيلة دفاع إضافية وليس حدًا أمنيًا كاملًا ضد SSRF. اعزل حركة المتصفح باستخدام Container أو VM أو Firewall أو Egress Proxy عند التعامل مع وجهات غير موثوقة.
- لا تسجل عناوين URL الخام أو بيانات البروكسي أو ملفات Cookies أو ترويسات Authorization أو ملفات حالة الجلسة. تنقّح المكتبة عددًا من القيم التشخيصية، لكن يجب على التطبيق حماية سجلاته وعمليات Pipeline الخاصة به أيضًا.

اقرأ [خيارات HTTP والأمان](http-and-security.md) و[تشغيل المتصفح](browser.md) قبل إتاحة الجلب لمستخدمين غير موثوقين.

## التثبيت

```bash
composer require helturkey/php-webtrawler
```

تتطلب وظائف HTTP والتحليل الأساسية PHP 8.4 أو أحدث والامتدادات الإلزامية الموضحة في [البدء السريع](getting-started.md). لا يحتاج جلب Guzzle العادي إلى تثبيت Playwright.

## فحوصات الجودة

```bash
composer validate --strict
composer cs
composer stan
composer test
composer check
```

راجع [الاختبارات وCI والإصدار](testing-and-release.md) لاختبارات النقل الاختيارية وLaravel وChromium والمواقع الحقيقية وإجراءات الإصدار.

## روابط

- [الوثائق العربية](README.md)
- [English documentation](../en/README.md)
- [متتبع المشكلات](https://github.com/helturkey/php-webtrawler/issues)
- [المستودع البرمجي](https://github.com/helturkey/php-webtrawler)
