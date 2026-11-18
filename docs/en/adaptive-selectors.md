# Adaptive selectors

Adaptive matching helps recover an element when a site's markup changes. It is a ranked similarity mechanism, not a semantic guarantee that an element is still the same business object.

## Capture a reference element

```php
$page = $scraper->get('https://example.com/catalog');
$button = $page->css('button.add-to-cart')->first();

if ($button === null) {
    throw new RuntimeException('Reference button was not found.');
}

$page->remember('catalog.add_to_cart', $button);
$selection = $page->adaptive('catalog.add_to_cart');
```

The snapshot is stored through the scraper's `SnapshotStore`. The default store is in-memory. Use `JsonSnapshotStore` when snapshots must persist across PHP processes:

```php
use HElturkey\WebTrawler\Storage\JsonSnapshotStore;

$store = new JsonSnapshotStore(__DIR__ . '/var/scraper-snapshots.json');
$scraper = Scraper::create(snapshotStore: $store);
```

Protect the snapshot file as application data. It can contain page-derived text, URLs, labels, and structural context. Do not expose it publicly.

## Matching and outcomes

The default matcher uses a weighted scoring profile and evaluates a bounded candidate set (default maximum 5,000). `match()` applies a default confidence threshold of `0.52`, a runner-up margin of `0.045`, and a result limit of 5 unless the caller changes them.

A result may indicate:

- accepted match;
- no snapshot;
- host mismatch;
- insufficient snapshot context;
- no candidate;
- low confidence;
- ambiguous candidates;
- candidate limit reached.

Review both the best candidate and the runner-up when making a consequential decision. A low score or small margin should cause the application to stop or request human review, not silently click a control.

`confidence` is a normalized similarity score. It is **not** a calibrated probability that the element is correct.

## Scoring profiles

`AdaptiveScoringProfile` configures finite non-negative weights for supported signals. Weights must be valid and cannot all be zero. Keep custom profiles under tests using a representative mutation corpus from your own target sites.

The matcher defaults to full candidate text for scoring. `maxCandidateTextChars` is an optional positive cap; enabling it can change scores for long text. The default `null` preserves full-text scoring behaviour.

## Host matching

Snapshots are associated with their source host. A host mismatch is not an ordinary low-confidence result; do not reuse a snapshot across unrelated hosts without an explicit, reviewed migration strategy.

## Persistence and concurrency

`InMemorySnapshotStore` is process-local. `JsonSnapshotStore` uses locking and atomic replacement for a local file. It is intended for a single host and a local filesystem; do not treat it as a distributed multi-node database or shared network-filesystem coordination mechanism.

For multi-worker deployments, implement `SnapshotStore` using a database or storage service with appropriate concurrency and access controls.

## Operational guidance

- Re-capture snapshots after deliberate site redesigns.
- Monitor accepted/rejected/ambiguous outcomes and inspect false positives.
- Keep mutation tests for selectors that trigger payments, account changes, submissions, deletion, or other side effects.
- Prefer explicit CSS/XPath selectors whenever stable selectors exist.
- Never automatically perform a sensitive action solely because an adaptive match was accepted.
