# Adaptive matcher benchmarks

## `adaptive-mutation.php` - synthetic DOM-mutation corpus

```bash
php benchmarks/adaptive-mutation.php --json=benchmarks/results/adaptive-mutation.json
php benchmarks/adaptive-mutation.php --assert      # CI gate: exit 1 on any wrong/false accept
```

Six templates (product list, article byline, table cell, nav link, comment author, form button) x twelve
variants (identity control, class rename, hashed CSS-in-JS classes, extra wrappers, tag swap, dropped ids and
test-ids, edited text, Arabic translation, reordered siblings, an inserted look-alike decoy, a combined
redesign, and target removal) = 72 cases. A snapshot is taken on the original page; on the mutated page the
matcher must find the same element or refuse.

Measured result (library 0.4.0, default thresholds), see `results/adaptive-mutation.json`:

| metric | value |
| --- | --- |
| cases / target present | 72 / 66 |
| wrong matches (accepted the wrong element) | **0** |
| false accepts when the target was removed | **0 of 6** |
| precision of accepted matches | 100 % |
| recall when the target exists | 93.9 % (62 of 66) |
| ground-truth marker influenced a result | 0 cases |

The four misses are conservative refusals (`ambiguous` or `low_confidence`), never wrong answers: text edited
on a product list, Arabic translation on a comment list, and the combined redesign on the article and form
templates.

### What this does and does not prove

* **It is a regression and relative-behaviour benchmark, not an accuracy claim.** The templates and mutations
  were written by the library author, so they share the author's assumptions. Six templates is a small sample.
* **It is not an independently reviewed real-world corpus.** That needs archived real pages (before/after pairs
  of the same site across a redesign), a second person to label the ground truth without seeing the matcher's
  output, and a published labelling protocol. The harness is built so such a corpus can be dropped in: add a
  template/variant, or replace `templates()` with loaded HTML pairs.
* Ground truth is a `data-bench-target` attribute added only to the mutated page; the "control" re-run without
  the attribute checks that it does not leak into scoring.

## Text-size equivalence (`tests/AdaptiveTextSizeTest.php`)

Reference confidences were produced by the unmodified 0.3.x matcher for a candidate of a given text length. The
default 0.4 matcher matches them to 6 decimals at 500 / 3 999 / 4 000 / 4 001 / 10 000 / 50 000 characters.

The optional `AdaptiveMatcher(maxCandidateTextChars: N)` compares only the first N characters of long
candidates. Measured against 0.3.x (same scenario):

| candidate chars | 0.3.x | exact (default) | capped at 4 000 | capped time vs exact |
| --- | --- | --- | --- | --- |
| 500 - 4 001 | identical | identical | identical | ~same |
| 10 000 | 0.352869 | 0.352869 | 0.376633 (+0.024) | 1.4x faster |
| 50 000 | 0.333105 | 0.333105 | 0.370404 (+0.037) | 2.9x faster |
| 200 000 | 0.329298 | 0.329298 | 0.369208 (+0.040) | 3.5x faster |

The cap removes the length penalty of very long text, so scores of long candidates rise. On a realistic
4 000-element page the cap gave no measurable speed-up (691 ms exact vs 731 ms capped), so exact scoring is the
default and the cap is an opt-in for pages with pathologically long single text nodes.

## Speed (same page, ~4 000 elements, 0.3.x vs 0.4)

1 734 ms -> 691 ms with identical scores (about 2.5x), 5 739 ms -> 1 498 ms at ~10 000 elements (3.8x). The gain
comes from memoising structural facts of shared ancestors per match.
