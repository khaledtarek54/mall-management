<?php

use Illuminate\Support\Facades\File;

/**
 * **A document series must be ordered by LENGTH before value** — EG-10.
 *
 * Every series in this system is allocated the same way: find the last number sharing a prefix, add
 * one. The lookup is `MAX` expressed as an `ORDER BY … LIMIT 1`, and the column is a STRING — so a
 * plain `orderByDesc('number')` sorts `INV-AW-9999` **above** `INV-AW-10000` and the allocator
 * proposes a number that already exists.
 *
 * ## Why this needs a gate rather than a fix
 *
 * Because it is invisible three times over.
 *
 * It was **unreachable** while every series reset monthly — nobody raises ten thousand invoices for
 * one mall in one month — and became routine the moment EG-10 made a continuous series the default.
 * So the bug is latent in exactly the allocators nobody has migrated yet.
 *
 * It is **masked end to end**: each allocator bumps until the number is free, so crossing the
 * boundary still produces a valid document. It costs a wasted query and only surfaces as a
 * duplicate-key race under concurrency.
 *
 * And it is a **set**, which is this codebase's most repeated defect — *fixed one instance, left the
 * siblings*. There are fifteen of these lookups across thirteen files. The EG-10 sweep corrected
 * fourteen and missed `PurchaseRequest::generatePoNumber()`, the method **immediately below** one it
 * had just fixed, in the same file. That is not carelessness; it is what enumerating from a diff
 * does instead of enumerating from the code.
 *
 * ## What this checks
 *
 * The allocator SHAPE — a `where('<col>', 'like', $prefix…)` — and then requires the ordering that
 * follows it to name `LENGTH(<col>)`. Keyed on `$prefix` deliberately: that is what distinguishes
 * "find the last one in this series" from any other prefix search, and a looser match would sweep
 * unrelated `like` filters and force exemptions that dilute the gate.
 */
it('orders every document-series lookup by length before value', function () {
    $pattern = "/->where\(\s*'([a-z_]+)'\s*,\s*'like'\s*,\s*\\\$prefix/";

    $unordered = [];
    $found = 0;

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = $file->getContents();

        if (! preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $column = $match[1][0];
            $found++;

            // The ordering is chained after the filter, within a few lines of it. A window rather
            // than a whole-file search, so one correct allocator cannot vouch for a broken sibling
            // in the same file — which is the exact miss this gate exists for.
            $after = substr($code, $match[0][1] + strlen($match[0][0]), 400);

            if (! str_contains($after, "LENGTH({$column})")) {
                $unordered[] = str_replace(base_path().'/', '', $file->getPathname())." :: {$column}";
            }
        }
    }

    // The sweep must have found something before it reports on nothing. A gate reporting on a set it
    // has silently stopped collecting is this project's other recurring failure, and it has shipped
    // three times — once staying green for a year while matching zero models.
    expect($found)->toBeGreaterThan(10);

    expect($unordered)->toBe([], implode("\n  ", array_merge(
        ['These document-series allocators order by a plain string sort, so once the series passes',
            'its zero-padding they return the wrong row and propose a number already taken:'],
        $unordered,
        ['Order by LENGTH(<column>) DESC, <column> DESC — as the other allocators do.'],
    )));
});
