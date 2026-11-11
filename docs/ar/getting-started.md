# البدء السريع

## المتطلبات

- PHP بإصدار `^8.4`.
- Composer 2.
- امتدادات PHP الإلزامية: `ext-dom` و`ext-json` و`ext-libxml` و`ext-xmlwriter`.
- اتصال شبكي خارجي بالموقع المستهدف لاستخدام جلب HTTP العادي.

تتطلب بعض الإمكانات امتدادات أو برامج إضافية:

| الإمكانية | المتطلب الإضافي |
|---|---|
| أسماء المضيفين الدولية IDN | `ext-intl` |
| تحويل أوسع أو أسرع للترميزات غير UTF-8 | `ext-mbstring`؛ ويُستخدم `iconv` كمسار احتياطي عند توفره |
| الإيقاف المنظم للـ CLI وحفظ الحالة عند الإشارات | `ext-pcntl` |
| فحص ملكية مجلدات جلسات المتصفح | `ext-posix` |
| تشغيل Chromium | `playwright-php/playwright` وNode.js 20+ وتثبيت Chromium الخاص بـ Playwright |
| نقل Impity | `dekor/php-impity` وملف تنفيذي متوافق من `curl-impersonate` |
| نقل Rnet | امتداد php-rnet الأصلي المتوافق مع ABI الخاص بـ PHP |
| تكامل Laravel | تطبيق Laravel 13؛ وتستخدم اختبارات الحزمة Testbench |

## التثبيت

```bash
composer require helturkey/php-webtrawler
```

يثبت Composer النواة الخاصة بجلب HTTP وتحليل الصفحات والزحف والمطابقة التكيفية والـ CLI والتصدير وMCP. الحزم الاختيارية ليست جزءًا من متطلبات التشغيل الأساسية؛ ثبّت التكامل الذي تحتاجه فقط.

## أول طلب

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = new FetchOptions(
    timeout: 20.0,
    connectTimeout: 5.0,
    blockPrivateNetworks: true,
    maxBodyBytes: 8 * 1024 * 1024,
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$page = $scraper->get('https://example.com/');
echo $page->title() ?? 'Untitled';
```

يفعّل المثال حظر الشبكات الخاصة صراحة. أما `Scraper::create()` دون إعدادات، فلا يحظر الشبكات الخاصة تلقائيًا في الاستخدام العام للمكتبة؛ لذلك يجب اختيار سياسة تناسب التطبيق وفرضها.

## تحليل الصفحة

```php
$firstHeading = $page->css('h1')->first();
$title = $firstHeading?->text();
$links = $page->css('a')->map(static fn ($link): array => [
    'text' => $link->text(),
    'href' => $link->attr('href'),
]);
```

تعيد `Page::css()` كائن `ElementCollection`. تعيد `first()` عنصرًا من النوع `Element|null`. وتوفر المجموعة أيضًا `all()` و`map()` و`each()` و`count()` و`isEmpty()`.

## إرسال POST

```php
use HElturkey\WebTrawler\Http\FetchOptions;

$page = $scraper->post(
    'https://example.com/search',
    ['q' => 'scraping'],
    new FetchOptions(headers: ['Accept' => 'text/html']),
);
```

يتم تمرير بيانات POST ضمن خيارات الطلب الخاصة بوسيلة النقل. لا تضع كلمات المرور أو مفاتيح API أو البيانات الشخصية في URL.

## الخطوات التالية

- راجع [خيارات HTTP والأمان](http-and-security.md) قبل قبول عناوين URL عشوائية.
- تعرّف على [الجلسات وتحليل الصفحات](fetching-and-parsing.md).
- أنشئ [Spider](spiders.md) لجمع صفحات متعددة.
- استخدم [المحددات التكيفية](adaptive-selectors.md) عندما تتغير بنية HTML بين التشغيلات.
