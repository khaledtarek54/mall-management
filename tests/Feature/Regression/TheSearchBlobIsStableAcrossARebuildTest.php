<?php

use App\Models\Tenant;

/**
 * **The search blob must not depend on JSON key order** — found by the MySQL QA harness, 2026-08-23.
 *
 * `Tenant::searchTextSources()` spreads `customFieldSearchValues()`, which iterated `metadata` in
 * whatever order the array came back in. `metadata` is a native `json` column on MySQL, and **MySQL
 * does not preserve object key order** — it normalises, shortest key first. So the order PHP wrote
 * was not the order the row read back in, and `atriom:rebuild-search` produced a different blob from
 * the one the save path had written: the same answers, resequenced.
 *
 *     written by the save path:  … 235264657 americana group fandb
 *     after atriom:rebuild-search: … 235264657 fandb americana group
 *
 * Harmless for substring matching, and still wrong twice over: the blob is documented as a pure
 * function of the row, and a rebuild that rewrites every blob buries a real change in the churn.
 *
 * **The whole Pest suite is blind to it** — on SQLite `metadata` is TEXT and keeps insertion order,
 * so the two paths agree here no matter what. That is why this is pinned as an ORDER-INDEPENDENCE
 * property rather than as a golden string: asserting the exact blob would pass on SQLite while the
 * bug was live on MySQL, which is precisely the trap that let it ship.
 */
it('folds custom-field answers in a stable order whatever order the row stores them in', function () {
    $keys = ['segment' => 'fandb', 'parent_group' => 'americana group', 'zone' => 'north'];

    $forward = Tenant::factory()->create();
    $forward->metadata = $keys;
    $forward->save();

    // The SAME answers, inserted in the opposite order — which is exactly what the difference
    // between PHP's insertion order and MySQL's normalised key order amounts to.
    $reverse = Tenant::factory()->create();
    $reverse->metadata = array_reverse($keys, preserve_keys: true);
    $reverse->save();

    expect($forward->customFieldSearchValues())
        ->toBe($reverse->customFieldSearchValues())
        ->and($forward->customFieldSearchValues())
        // Key order, not insertion order: `parent_group` < `segment` < `zone`.
        ->toBe(['americana group', 'fandb', 'north']);
});
