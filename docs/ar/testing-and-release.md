# الاختبارات وCI والإصدار

## فحوصات الجودة المحلية

يستهدف المشروع PHP 8.4 كحد أدنى وبيئة الإصدار الأساسية. شغّل الفحوصات على PHP 8.4؛ وتختبر CI أيضًا PHP 8.5 للتوافق المستقبلي.

```bash
composer validate --strict
composer cs
composer stan
composer test
composer check
```

ينفذ `composer check` فحوص PHP-CS-Fixer وPHPStan ومجموعة PHPUnit الأساسية. ولا يشغّل وحده كل اختبارات التكامل الاختيارية أو Native أو المتصفح أو المواقع الحقيقية.

تم تقسيم الاختبارات بحسب الطبقة: `tests/Unit/{Adaptive,Browser,Concurrency,Export,Fetching,Frontend,Package,Parsing,Policy,Proxy,Retry,Spider,Storage,Support,Transport}` و`tests/Integration/{Live,Local,Optional}`. يمكن تشغيل كل مجموعة منفصلة عند تتبع مشكلة:

```bash
composer test-unit
composer test-integration
```

## اختبارات التكامل

### Laravel

```bash
composer test-laravel
```

يشغل مجموعة تكامل الحزمة مع Testbench. اختبر أيضًا الحزمة داخل تطبيقك الفعلي بإصدار Laravel وإعداداته المستخدمة في الإنتاج.

### Impity

ثبّت ملف `curl-impersonate` متوافقًا واضبط:

```bash
export IMPITY_CURL_IMPERSONATE_PATH=/absolute/path/to/curl-impersonate
composer test-impity
```

يعني تخطي الاختبار أن التكامل الاختياري لم يعمل. وتتعامل CI مع أي اختبار متخطى في وظيفة Impity المخصصة على أنه فشل.

### php-rnet

ابنِ/ثبّت الامتداد للإصدار الدقيق من PHP ABI والمعمارية، ثم نفّذ:

```bash
php -d extension=/absolute/path/to/librnet.so vendor/bin/phpunit \
  --filter test_the_php_rnet_adapter_against_the_real_extension \
  tests/Integration/Local/LocalServerIntegrationTest.php
```

تبني CI إصدار php-rnet المحدد وتختبره مع PHP 8.4 على Linux x86_64. لا يمكن افتراض أن ملف `.so` المبني لـ PHP 8.4 Linux x86_64 سيعمل مع PHP ABI مختلف أو macOS ARM64.

### Chromium في شبكة معزولة

ثبّت Playwright وChromium، ثم:

```bash
SCRAPER_RUN_BROWSER_INTEGRATION=1 composer test-browser
```

لا يكون اختبار العزل الشبكي ذا معنى إلا إذا كانت العملية تعمل فعلًا داخل Container أو VM معزول شبكيًا. تشغّل صورة Docker في CI باستخدام `--network none`، وتمرر `SCRAPER_BROWSER_EGRESS_ISOLATED=1` وقت تشغيل الحاوية فقط. لا تضبط هذا المتغير في عملية محلية عادية متصلة بالشبكة.

### اختبارات الخدمات الحية

تستخدم مجموعة الاختبارات الحية المواقع والمحددات نفسها الموجودة في أمثلة الحزمة: `https://quotes.toscrape.com/` للاقتباسات و`https://books.toscrape.com/` للكتب. وتختبر مسار الجلب الفعلي (`Downloader` و`Fetcher` و`Scraper` و`Session` وParser وواجهة Page)، ووسائل النقل المثبتة، وطبقات المتصفح، وSpider وPipeline، وإزالة التكرار عبر SQLite عند توفر `pdo_sqlite`، وAdaptive matching، والمصدّرات. يتم تخطي وسائل النقل الاختيارية واختبارات المتصفح عند غياب متطلباتها. ويتطلب اختبار مسار Proxy ضبط `SCRAPER_TEST_PROXY`.

تُستبعد هذه الاختبارات عمدًا من `composer test` العادي لأنها تحتاج اتصالًا خارجيًا وتعتمد على توفر مواقع الطرف الثالث. شغّلها صراحة:

```bash
SCRAPER_RUN_LIVE_TESTS=1 composer test-live
```

لا توجد حاليًا وظيفة في GitHub Actions لاختبار المواقع الحية العامة. هذه الاختبارات اختيارية ولا تعمل إلا عند طلب تشغيلها صراحة. قد يعكس الفشل تغير الموقع البعيد أو تحديد المعدل أو مشكلة توفر خارجية، وليس بالضرورة تراجعًا محليًا؛ تحقق من السبب قبل إعادة المحاولة.

### غياب وسائل النقل الاختيارية

يتحقق `tests/Unit/Transport/OptionalDriverAvailabilityTest.php` من أن غياب حزم Amp وImpity وPlaywright ينتج رسائل تثبيت واضحة. تعمل هذه الاختبارات ضمن مجموعة PHPUnit العادية، وتتخطى حالة الوسيلة إذا كانت حزمتها الاختيارية مثبتة.

## التحقق الموحد باستخدام Docker

شغّل فحوص صورة الإنتاج واختبارات التكامل الخاصة بـ Docker بأمر واحد:

```bash
composer test-docker
```

يبني الأمر صورة الإنتاج وينفذ فحوص Smoke الخاصة بها باعتبارها اعتمادًا لازمًا لصورة `ci` الموحدة. بعد ذلك يشغّل صورة CI مرة واحدة مع تعطيل الاتصال بالشبكات الخارجية. تنفذ الصورة اختبارات Playwright/Chromium المحلية، واختبارات Impity باستخدام curl-impersonate، واختبارات امتداد php-rnet الأصلي بالتتابع. لا يُسمح بتخطي اختبارات المتصفح أو وسائل النقل المطلوبة.

يستخدم هدف Docker الكامل معمارية `linux/amd64` لأن امتداد php-rnet يُبنى ويُختبر لهذه المعمارية. تحتاج أجهزة Apple Silicon إلى تفعيل محاكاة AMD64 في Docker VM. تحتوي صورة CI على المترجمات واعتماديات التطوير ولا ينبغي نشرها؛ هدف `runtime` وحده مخصص للإنتاج.

تغطي هذه العملية فحوص Smoke لصورة الإنتاج واختبارات التكامل المخصصة للمتصفح ووسائل النقل الأصلية. لكنها لا تستبدل `composer check` أو مجموعة PHPUnit العادية أو اختبارات تكامل Laravel أو اختبارات المواقع الحية الاختيارية.

راجع [دليل تشغيل Docker والنشر والاختبارات](docker.md) لمعرفة المتطلبات وطريقة استخدام الصور وتغطية الاختبارات والتخزين المؤقت وحل المشكلات.

## مصفوفة CI وبوابات الإصدار

تشغّل Workflow الموجودة حاليًا في GitHub Actions ما يلي:

- مجموعة الجودة على PHP 8.4 وPHP 8.5، وتشمل التحقق من Composer وPHP-CS-Fixer وPHPStan وPHPUnit واختبارات تكامل Laravel؛
- وظيفة منفصلة لاختبارات Chromium تستخدم خادم الاختبار المحلي داخل Docker مع تعطيل الاتصال بالشبكات الخارجية.

إعداد Docker الخاص بوسيلتي النقل php-rnet وImpity موجود تحت `docker/`، لكنه غير موصول حاليًا بملف `.github/workflows/ci.yml`. كما أن اختبارات المواقع العامة الحية ليست جزءًا من GitHub Actions؛ شغّلها صراحة باستخدام `composer test-live` كما سبق.
