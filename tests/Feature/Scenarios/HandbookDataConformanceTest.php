<?php

use App\Services\Accounting\LedgerPoster;
use App\Support\ScreenGuides;
use Illuminate\Support\Facades\Artisan;

/**
 * The handbook's generated datasets still describe the system.
 *
 * `GeneratedDocsConformanceTest` already does this for the markdown docs, and for the same reason:
 * `docs/modules/21-general-ledger.md` once described 12 posting sources while `LedgerPoster` posted
 * 21, and nothing noticed for months. A DIAGRAM is worse than a paragraph for that — a picture of a
 * system reads as authoritative in a way prose does not, so the drift is even less likely to be
 * questioned by whoever is looking at it.
 *
 * The check is the same shape as the docs one: re-run the generator and compare. A run that leaves
 * the tree dirty means someone added a GL source, a screen or a workflow and did not regenerate.
 */
it('keeps the handbook datasets in step with the registries', function () {
    $dir = base_path('docs/visual/.vitepress/data');

    $before = [];
    foreach (glob("{$dir}/*.json") ?: [] as $file) {
        $before[basename($file)] = file_get_contents($file);
    }

    expect($before)->not->toBeEmpty('No handbook datasets found — run `php artisan atriom:dump-handbook-data`.');

    Artisan::call('atriom:dump-handbook-data');

    $drifted = [];
    foreach (glob("{$dir}/*.json") ?: [] as $file) {
        $name = basename($file);

        if (($before[$name] ?? null) !== file_get_contents($file)) {
            $drifted[] = $name;
        }
    }

    expect($drifted)->toBe([], "These handbook datasets are stale — run `php artisan atriom:dump-handbook-data`\n"
        ."and commit the result:\n  ".implode("\n  ", $drifted));
})->group('conformance');

it('describes every GL source and every screen, not a subset', function () {
    $dir = base_path('docs/visual/.vitepress/data');

    $sources = json_decode((string) file_get_contents("{$dir}/gl-sources.json"), true);
    $screens = json_decode((string) file_get_contents("{$dir}/screens.json"), true);

    // The count is the whole point: a dataset built from a hand-typed list would drift to a subset
    // and still look plausible, which is the failure the GL doc actually shipped.
    expect($sources)->toHaveCount(count(LedgerPoster::JOURNALIZERS));
    expect($screens)->toHaveCount(count(ScreenGuides::SCREENS));
})->group('conformance');

it('does not claim a money record can be deleted', function () {
    // A regression guard on a real defect in the generator, not a hypothetical. `NEVER_DELETABLE`
    // is keyed BY CLASS with the remedy as the value, and the first version searched it with
    // `in_array($model, ...)` — which compares against the REASONS. Every money record therefore
    // dumped as freely deletable, and the handbook would have drawn the exact inverse of the
    // project's most-stated invariant, confidently.
    $sources = json_decode(
        (string) file_get_contents(base_path('docs/visual/.vitepress/data/gl-sources.json')),
        true
    );

    $byModel = array_column($sources, 'deletable', 'model');

    foreach (['Invoice', 'Payment', 'CreditNote', 'VendorBill', 'Expense', 'Payroll', 'DepositTransaction'] as $model) {
        expect($byModel[$model]['tier'] ?? null)->toBe('never', "{$model} is a money record and must never dump as deletable.");
        expect($byModel[$model]['instead'] ?? '')->not->toBe('', "{$model} must state the workflow that corrects it instead.");
    }
})->group('conformance');
