# تكامل Laravel

تتكامل المكتبة مع Laravel 13 عبر الاكتشاف التلقائي للحزمة ومزود الخدمة وFacade باسم `Scrape` وملف الإعداد وأمر Artisan. تختبر CI أزواج توافق Laravel 13 وTestbench محددة؛ شغّل اختباراتك على إصدار Laravel الفعلي في تطبيقك.

## التثبيت

```bash
composer require helturkey/php-webtrawler
php artisan vendor:publish --tag=scraper-config
```

يُعلن مزود الخدمة في بيانات Composer الخاصة بالحزمة، ومن المفترض أن يكتشفه Laravel تلقائيًا.

## الإعدادات

يتضمن `config/scraper.php` المنشور الخيارات التالية:

| المفتاح | متغير البيئة | الافتراضي |
|---|---|---|
| `transport` | `SCRAPER_TRANSPORT` | `guzzle` |
| `emulation` | `SCRAPER_EMULATION` | `null` |
| `timeout` | `SCRAPER_TIMEOUT` | 30 ثانية |
| `connect_timeout` | `SCRAPER_CONNECT_TIMEOUT` | 10 ثوانٍ |
| `max_body_bytes` | `SCRAPER_MAX_BODY_BYTES` | 20 MiB |
| `block_private_networks` | `SCRAPER_BLOCK_PRIVATE_NETWORKS` | `true` |
| `allowed_hosts` | `SCRAPER_ALLOWED_HOSTS` | قائمة فارغة |
| `enforce_default_security` | `SCRAPER_ENFORCE_DEFAULT_SECURITY` | `true` |
| `retries.max_attempts` | `SCRAPER_RETRY_ATTEMPTS` | `3` |
| `retries.base_delay_ms` | — | `500` |
| `retries.max_delay_ms` | — | `30000` |
| `proxies` | `SCRAPER_PROXIES` | قائمة فارغة |
| `snapshots.driver` | `SCRAPER_SNAPSHOTS` | `file` |
| `snapshots.path` | — | `storage/app/scraper/snapshots.json` |

`SCRAPER_ALLOWED_HOSTS` قائمة مضيفين مفصولة بفواصل. أما `SCRAPER_PROXIES` فقائمة بروكسي مفصولة بفواصل؛ استخدم مدير أسرار أو حقن متغيرات البيئة ولا تحفظ بيانات الاعتماد في Git.

يفعّل مزود خدمة Laravel فرض إعدادات الأمان الافتراضية. يمكن لخيارات الطلب تضييق السياسة، لكن لا يمكنها تعطيل حظر الشبكات الخاصة أو رفع حد الجسم أو توسيع قائمة المضيفين أو تعطيل TLS المطلوب أو استبدال مسار بروكسي مفروض.

## استخدام Facade

```php
use HElturkey\WebTrawler\Laravel\Facades\Scrape;

$page = Scrape::get('https://docs.example.com/');
$title = $page->title();
$links = $page->css('a')->map(static fn ($link): array => [
    'text' => $link->text(),
    'href' => $link->attr('href'),
]);
```

تحل Facade كائن Scraper المفرد المسجل في الحاوية. استخدم Facade أو Dependency Injection بحسب أسلوب التطبيق.

## أمر Artisan

يسجل المشروع الأمر `scraper:fetch`. افحص الخيارات المتاحة في النسخة المثبتة:

```bash
php artisan list scraper
php artisan help scraper:fetch
```

## البروكسي وتشغيل المتصفح

تطبق مجموعة البروكسي المضبوطة على طلبات HTTP وSpider. ولا تطبق تلقائيًا على Playwright. يحتاج المتصفح إلى إعداد Proxy خاص به أو توجيه خروج شبكي إلزامي.

## ملاحظات تشغيلية

- أبقِ `block_private_networks=true` عندما يمكن للمستخدم التأثير في URL.
- أضف قائمة مضيفين للمجالات المعروفة كلما أمكن.
- احفظ اللقطات في مساحة تخزين Laravel الخاصة، وضع سياسة للاحتفاظ والنسخ الاحتياطي.
- لا تعرض HTML الخام المستخرج داخل Blade دون Escaping أو Sanitization مناسب.
- أضف اختبارات في التطبيق المستهلك لإعدادات Provider واستخدام Facade والصلاحيات وحدود المعدل والحفظ. اختبارات تكامل الحزمة لا تحل محل اختبارات التطبيق نفسه.
