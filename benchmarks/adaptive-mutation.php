<?php

declare(strict_types=1);

/**
 * Synthetic DOM-mutation benchmark for the adaptive matcher.
 *
 *   php benchmarks/adaptive-mutation.php [--json=benchmarks/results/adaptive-mutation.json]
 *
 * For each template a target element is snapshotted on the original page, the page is then mutated the way
 * real redesigns tend to mutate markup, and the matcher must either find the same element or refuse.
 * Ground truth is a data-bench-target attribute added ONLY to the mutated page; the harness verifies that
 * the attribute itself does not influence the result (see "control").
 *
 * LIMITS: the templates and mutations are written by the library author. This measures regression and
 * relative behaviour, not accuracy on the real web. See benchmarks/README.md.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;

final class Variant
{
    private const AR = [
        'Add to cart' => 'أضف إلى السلة', 'Pricing' => 'الأسعار', 'Reply' => 'رد', 'Submit order' => 'إرسال الطلب',
        'Maya Hassan' => 'مايا حسن', 'Omar Said' => 'عمر سعيد', 'Laila Nour' => 'ليلى نور',
    ];

    /** @param array<string, bool> $f */
    public function __construct(
        public readonly string $name,
        public readonly bool $after,
        private readonly array $f,
    ) {
    }

    public function c(string $class): string
    {
        if ($this->f['hash'] ?? false) {
            return 'css-' . substr(md5($class), 0, 6) . ' ' . 'sc-' . substr(md5($class . 'x'), 0, 5);
        }

        return ($this->f['rename'] ?? false) ? 'x-' . strrev($class) : $class;
    }

    public function t(string $tag): string
    {
        if (!($this->f['tag'] ?? false)) {
            return $tag;
        }

        return ['h2' => 'h3', 'div' => 'section', 'span' => 'strong', 'p' => 'div', 'a' => 'a', 'li' => 'li', 'td' => 'td'][$tag] ?? $tag;
    }

    public function w(string $html): string
    {
        return ($this->f['wrap'] ?? false) ? '<div class="' . $this->c('layout-wrapper') . '"><div>' . $html . '</div></div>' : $html;
    }

    public function x(string $text): string
    {
        if ($this->f['translate'] ?? false) {
            return self::AR[$text] ?? $text;
        }
        if ($this->f['edit'] ?? false) {
            return preg_match('/\d/', $text) === 1 ? (string) preg_replace_callback('/\d+/', static fn (array $m): string => (string) ((int) $m[0] + 3), $text) : $text . ' now';
        }

        return $text;
    }

    public function ids(string $name): string
    {
        return ($this->f['dropIds'] ?? false) ? '' : ' id="' . $name . '" data-testid="' . $name . '"';
    }

    public function mark(bool $isTarget): string
    {
        return $this->after && $isTarget ? ' data-bench-target="1"' : '';
    }

    public function removed(): bool
    {
        return $this->f['remove'] ?? false;
    }

    public function decoy(): bool
    {
        return $this->f['decoy'] ?? false;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    public function reversed(array $items): array
    {
        return ($this->f['reorder'] ?? false) ? array_reverse($items) : $items;
    }
}

/** @return array<string, array{selector: string, build: Closure(Variant): string}> */
function templates(): array
{
    return [
        'product-list' => ['selector' => '.price[data-pos="3"]', 'build' => function (Variant $v): string {
            $cards = [];
            foreach ([1 => ['Trail Shoes', '$89.00'], 2 => ['Rain Jacket', '$129.50'], 3 => ['Wool Socks', '$14.25'], 4 => ['Day Pack', '$64.00'], 5 => ['Water Bottle', '$22.90']] as $n => [$name, $price]) {
                $target = $n === 3;
                $priceHtml = $v->removed() && $target ? '' : '<' . $v->t('span') . ' class="' . $v->c('price') . '" data-pos="' . $n . '"' . $v->mark($target) . '>' . $v->x($price) . '</' . $v->t('span') . '>';
                $decoy = $v->decoy() && $target ? '<' . $v->t('span') . ' class="' . $v->c('price') . '">' . $v->x('$31.10') . '</' . $v->t('span') . '>' : '';
                $cards[] = '<article class="' . $v->c('product-card') . '"><' . $v->t('h2') . ' class="' . $v->c('product-title') . '">' . $v->x($name) . '</' . $v->t('h2') . '>' . $decoy . $priceHtml . '<button class="' . $v->c('buy') . '">' . $v->x('Add to cart') . '</button></article>';
            }

            return '<main>' . $v->w('<section class="' . $v->c('catalog') . '"><h1>Shop</h1>' . implode('', $v->reversed($cards)) . '</section>') . '</main>';
        }],
        'article-author' => ['selector' => 'a.author', 'build' => function (Variant $v): string {
            $author = $v->removed() ? '' : '<a class="' . $v->c('author') . '" href="/people/maya"' . $v->ids('author-link') . $v->mark(true) . '>' . $v->x('Maya Hassan') . '</a>';
            $decoy = $v->decoy() ? '<a class="' . $v->c('author') . '" href="/people/guest">Guest Editor</a>' : '';

            return '<main><article>' . $v->w('<header><h1>How rivers shape cities</h1><' . $v->t('div') . ' class="' . $v->c('byline') . '">By ' . $decoy . $author . ' · 6 min read</' . $v->t('div') . '></header><p>Cities grew along water.</p><p>Bridges followed trade.</p>') . '</article></main>';
        }],
        'table-cell' => ['selector' => 'td.qty[data-row="3"]', 'build' => function (Variant $v): string {
            $rows = [];
            foreach ([1 => ['Bolts', '120'], 2 => ['Nuts', '340'], 3 => ['Washers', '575'], 4 => ['Screws', '910']] as $n => [$item, $qty]) {
                $target = $n === 3;
                $cell = $v->removed() && $target ? '' : '<td class="' . $v->c('qty') . '" data-row="' . $n . '"' . $v->mark($target) . '>' . $v->x($qty) . '</td>';
                $rows[] = '<tr class="' . $v->c('row') . '"><td class="' . $v->c('item') . '">' . $v->x($item) . '</td>' . $cell . '</tr>';
            }

            return '<main>' . $v->w('<table class="' . $v->c('stock') . '"><thead><tr><th>Item</th><th>Qty</th></tr></thead><tbody>' . implode('', $v->reversed($rows)) . '</tbody></table>') . '</main>';
        }],
        'nav-link' => ['selector' => 'nav a[data-nav="pricing"]', 'build' => function (Variant $v): string {
            $links = [];
            foreach (['home' => 'Home', 'features' => 'Features', 'pricing' => 'Pricing', 'blog' => 'Blog', 'contact' => 'Contact'] as $slug => $label) {
                if ($v->removed() && $slug === 'pricing') {
                    continue;
                }
                $target = $slug === 'pricing';
                $links[] = '<li class="' . $v->c('nav-item') . '"><a class="' . $v->c('nav-link') . '" href="/' . $slug . '" data-nav="' . $slug . '"' . $v->mark($target) . '>' . $v->x($label) . '</a></li>';
                if ($v->decoy() && $target) {
                    $links[] = '<li class="' . $v->c('nav-item') . '"><a class="' . $v->c('nav-link') . '" href="/plans">Plans</a></li>';
                }
            }

            return '<header>' . $v->w('<nav class="' . $v->c('main-nav') . '"><ul>' . implode('', $v->reversed($links)) . '</ul></nav>') . '</header>';
        }],
        'comment-author' => ['selector' => '.comment[data-n="2"] .name', 'build' => function (Variant $v): string {
            $comments = [];
            foreach ([1 => ['Omar Said', 'Great read.'], 2 => ['Laila Nour', 'I disagree about bridges.'], 3 => ['Maya Hassan', 'Thanks all.']] as $n => [$name, $body]) {
                $target = $n === 2;
                $nameHtml = $v->removed() && $target ? '' : '<' . $v->t('span') . ' class="' . $v->c('name') . '"' . $v->mark($target) . '>' . $v->x($name) . '</' . $v->t('span') . '>';
                $decoy = $v->decoy() && $target ? '<' . $v->t('span') . ' class="' . $v->c('name') . '">Moderator</' . $v->t('span') . '>' : '';
                $comments[] = '<' . $v->t('div') . ' class="' . $v->c('comment') . '" data-n="' . $n . '">' . $decoy . $nameHtml . '<p class="' . $v->c('body') . '">' . $v->x($body) . '</p><a class="' . $v->c('reply') . '" href="#r' . $n . '">' . $v->x('Reply') . '</a></' . $v->t('div') . '>';
            }

            return '<main>' . $v->w('<section class="' . $v->c('comments') . '"><h2>Comments</h2>' . implode('', $v->reversed($comments)) . '</section>') . '</main>';
        }],
        'form-submit' => ['selector' => 'button[type="submit"]', 'build' => function (Variant $v): string {
            $submit = $v->removed() ? '' : '<button type="submit" class="' . $v->c('btn-primary') . '"' . $v->ids('submit') . $v->mark(true) . '>' . $v->x('Submit order') . '</button>';
            $decoy = $v->decoy() ? '<button type="button" class="' . $v->c('btn-primary') . '">Cancel</button>' : '';

            return '<main>' . $v->w('<form class="' . $v->c('checkout') . '" action="/order" method="post"><label class="' . $v->c('field') . '">Name<input name="name"></label><label class="' . $v->c('field') . '">Email<input name="email" type="email"></label>' . $decoy . $submit . '</form>') . '</main>';
        }],
    ];
}

$mutations = [
    'identity (control)' => [],
    'class rename' => ['rename' => true],
    'hashed classes (CSS-in-JS)' => ['hash' => true],
    'extra wrappers' => ['wrap' => true],
    'tag swap' => ['tag' => true],
    'ids/test-ids dropped' => ['dropIds' => true],
    'text edited' => ['edit' => true],
    'text translated (ar)' => ['translate' => true],
    'siblings reordered' => ['reorder' => true],
    'decoy inserted' => ['decoy' => true],
    'combined redesign' => ['rename' => true, 'wrap' => true, 'tag' => true, 'dropIds' => true],
    'target removed (must refuse)' => ['remove' => true],
];

$matcher = new AdaptiveMatcher();
$rows = [];
$failures = [];
$controlDrift = 0;

foreach (templates() as $templateName => $template) {
    $url = 'https://bench.example/' . $templateName;
    $store = new InMemorySnapshotStore();
    $before = Page::fromHtml($template['build'](new Variant('before', false, [])), $url, $store, $matcher);
    $element = $before->css($template['selector'])->first();
    if ($element === null) {
        fwrite(STDERR, "Template $templateName: selector did not match the original page.\n");
        exit(1);
    }
    $snapshot = ElementSnapshot::capture($element, $url);

    foreach ($mutations as $mutationName => $flags) {
        $variant = new Variant($mutationName, true, $flags);
        $html = $template['build']($variant);
        $result = $matcher->match(Page::fromHtml($html, $url, $store, $matcher), $snapshot);
        $best = $result->candidates[0] ?? null;
        $correct = $result->accepted && $best !== null && $best->element()->hasAttr('data-bench-target');
        $shouldRefuse = $variant->removed();

        // Control: the ground-truth marker must not change the outcome.
        $unmarked = str_replace(' data-bench-target="1"', '', $html);
        $control = $matcher->match(Page::fromHtml($unmarked, $url, $store, $matcher), $snapshot);
        if ($control->status !== $result->status || abs((isset($control->candidates[0]) ? $control->candidates[0]->confidence : 0.0) - ($best !== null ? $best->confidence : 0.0)) > 1e-9) {
            $controlDrift++;
        }

        $outcome = match (true) {
            $shouldRefuse && !$result->accepted => 'correct_refusal',
            $shouldRefuse && $result->accepted => 'false_accept',
            $correct => 'correct_match',
            $result->accepted => 'wrong_match',
            default => 'missed',
        };
        $rows[$mutationName][$outcome] = ($rows[$mutationName][$outcome] ?? 0) + 1;
        if (in_array($outcome, ['wrong_match', 'false_accept', 'missed'], true)) {
            $failures[] = ['template' => $templateName, 'mutation' => $mutationName, 'outcome' => $outcome, 'status' => $result->status, 'confidence' => round($best !== null ? $best->confidence : 0.0, 3), 'margin' => $result->margin === null ? null : round($result->margin, 3)];
        }
    }
}

$totals = ['correct_match' => 0, 'wrong_match' => 0, 'missed' => 0, 'correct_refusal' => 0, 'false_accept' => 0];
printf("%-32s %7s %7s %7s | %7s %7s\n", 'mutation', 'match', 'wrong', 'missed', 'refused', 'f-accpt');
foreach ($rows as $name => $counts) {
    foreach ($totals as $key => $_) {
        $totals[$key] += $counts[$key] ?? 0;
    }
    printf("%-32s %7d %7d %7d | %7d %7d\n", $name, $counts['correct_match'] ?? 0, $counts['wrong_match'] ?? 0, $counts['missed'] ?? 0, $counts['correct_refusal'] ?? 0, $counts['false_accept'] ?? 0);
}

$present = $totals['correct_match'] + $totals['wrong_match'] + $totals['missed'];
$accepted = $totals['correct_match'] + $totals['wrong_match'];
$removals = $totals['correct_refusal'] + $totals['false_accept'];
$summary = [
    'cases' => $present + $removals,
    'target_present_cases' => $present,
    'precision_of_accepted' => $accepted > 0 ? round($totals['correct_match'] / $accepted, 4) : null,
    'recall_when_present' => $present > 0 ? round($totals['correct_match'] / $present, 4) : null,
    'wrong_accept_rate_when_present' => $present > 0 ? round($totals['wrong_match'] / $present, 4) : null,
    'false_accept_rate_when_removed' => $removals > 0 ? round($totals['false_accept'] / $removals, 4) : null,
    'marker_influence_drift_cases' => $controlDrift,
];
echo "\n", json_encode($summary, JSON_PRETTY_PRINT), "\n";
if ($failures !== []) {
    echo "\nNon-ideal cases:\n";
    foreach ($failures as $f) {
        printf("  %-15s %-30s %-13s status=%s conf=%s margin=%s\n", $f['template'], $f['mutation'], $f['outcome'], $f['status'], $f['confidence'], var_export($f['margin'], true));
    }
}

$jsonPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    }
}
if ($jsonPath !== null) {
    file_put_contents($jsonPath, json_encode(['summary' => $summary, 'by_mutation' => $rows, 'non_ideal' => $failures, 'generated' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
}

// CI gate: a wrong or false accept (or the ground-truth marker leaking into the result) is always a regression.
if (in_array('--assert', $argv, true) && ($totals['wrong_match'] > 0 || $totals['false_accept'] > 0 || $controlDrift > 0)) {
    fwrite(STDERR, "\nBenchmark gate failed: wrong accepts, false accepts or marker drift detected.\n");
    exit(1);
}
