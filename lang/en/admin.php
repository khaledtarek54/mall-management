<?php

/**
 * Admin translations, split into per-domain partials (2026-08-15).
 *
 * This file used to hold all 5,564 lines, and was touched by 161 of the last 400 commits — 40% of
 * all work, and three times the next file. Every feature added keys here, so every feature
 * conflicted with every other feature.
 *
 * **Key paths are unchanged.** This merges `admin/*.php` and returns exactly what it always
 * returned, so `__('admin.fields.name')` still resolves and nothing that reads this file as one
 * array had to change. Add a new key to the partial its domain belongs to; add a new partial by
 * dropping a file in `admin/` — it is picked up by the sort below, no registration needed.
 *
 * The cross-cutting catalogues (fields, actions, statuses, tables…) are separate files on purpose:
 * they are the ones every feature touches, so isolating them is what stops a billing change and a
 * facility change colliding when they have nothing to do with each other.
 */
$merged = [
];

foreach (glob(__DIR__.'/admin/*.php') ?: [] as $partial) {
    $keys = require $partial;

    // A key defined in two partials would be silently resolved by load order, which is nobody's
    // decision. Fail loudly instead. This runtime guard is the ONLY check on cross-partial
    // collisions — TranslationKeyConformanceTest catches duplicates WITHIN one file, not across two.
    if ($clash = array_intersect_key($keys, $merged)) {
        throw new LogicException(sprintf(
            'Duplicate admin translation key(s) in %s: %s',
            basename($partial),
            implode(', ', array_keys($clash))
        ));
    }

    $merged += $keys;
}

return $merged;
