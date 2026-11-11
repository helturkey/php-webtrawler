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
  tests/LocalServerIntegrationTest.php
```

تبني CI إصدار php-rnet المحدد وتختبره مع PHP 8.4 على Linux x86_64. لا يمكن افتراض أن ملف `.so` المبني لـ PHP 8.4 Linux x86_64 سيعمل مع PHP ABI مختلف أو macOS ARM64.

### Chromium في شبكة معزولة

ثبّت Playwright وChromium، ثم:

```bash
SCRAPER_RUN_BROWSER_INTEGRATION=1 composer test-browser
```

لا يكون اختبار العزل الشبكي ذا معنى إلا إذا كانت العملية تعمل فعلًا داخل Container أو VM معزول شبكيًا. تشغّل صورة Docker في CI باستخدام `--network none`، وتمرر `SCRAPER_BROWSER_EGRESS_ISOLATED=1` وقت تشغيل الحاوية فقط. لا تضبط هذا المتغير في عملية محلية عادية متصلة بالشبكة.

### اختبارات المتصفح على مواقع حقيقية

يتم استبعاد اختباري المواقع العامة عمدًا من `composer test` العادي؛ لأنهما يحتاجان اتصالًا خارجيًا ويعتمدان على بقاء مواقع الطرف الثالث متاحة. شغلهما صراحة:

```bash
SCRAPER_RUN_LIVE_TESTS=1 composer test-live
```

وظيفة CI الحية يدوية واختيارية (`workflow_dispatch`). قد يعكس الفشل تغير الموقع البعيد أو تحديد المعدل أو مشكلة توفر خارجية، وليس بالضرورة تراجعًا محليًا؛ تحقق من السبب قبل إعادة المحاولة.

### اختبار غياب الحزم الاختيارية

تثبت CI الحزمة باعتبارها Path Dependency داخل Fixture مستهلكة، دون تثبيت اعتماديات `require-dev` الخاصة بالحزمة. يتحقق الاختبار من غياب Impity وPlaywright ومن ظهور رسائل واضحة عند محاولة استخدامهما. أبقِ الحزم الاختيارية ضمن `require-dev` فقط إذا كانت للتطوير والاختبار؛ ولا تنقلها إلى `require` الأساسي إلا إذا كانت النواة تعتمد عليها فعلًا.

## مصفوفة CI وبوابات الإصدار

تشمل Workflow:

- PHP 8.4 مع أحدث الاعتماديات؛
- PHP 8.4 مع أقل الاعتماديات المدعومة؛
- PHP 8.5 مع أحدث الاعتماديات؛
- أزواج توافق Laravel/Testbench على PHP 8.4؛
- بناء واختبار امتداد php-rnet الأصلي؛
- اختبار Impity مع curl-impersonate؛
- اختبار Chromium مع عزل الشبكة الخارجية؛
- تثبيت مستهلك دون الحزم الاختيارية؛
- اختبارات متصفح حية اختيارية.
