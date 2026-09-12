<?php

use App\Support\PhpSource;

/**
 * Every index a migration lets Laravel NAME must fit MySQL's 64-character identifier limit.
 *
 * Laravel derives an index name from the table and the columns —
 * `{table}_{col1}_{col2}_{type}` — and sqlite accepts any length, so the suite is green on a
 * name MySQL refuses with `1059 Identifier name … is too long`. That happened on 2026-09-12: the
 * point-18 migration's `unique(['fixed_asset_transfer_id', 'direction'])` on
 * `fixed_asset_transfer_legs` derives to 66 characters, every test passed, and the first deploy
 * died mid-migration with both tables created and neither index — the `tests/Mysql` tier would
 * have seen it, and nobody runs that tier on a laptop before a push. This runs on every push.
 *
 * A gate that reads source: it tokenises comments away first (`PhpSource`), finds each
 * `Schema::create('table', …)` / `Schema::table('table', …)` block, and inside it every
 * `->unique(…)` / `->index(…)` / `->foreign(…)` / `foreignId('x')->…constrained(` that passes NO
 * explicit name. A stated name is the author's to keep short; MySQL will refuse a long one loudly
 * at the first migrate, which this gate does not second-guess.
 */
function migrationIndexOverruns(): array
{
    $overruns = [];
    $examined = 0;

    foreach (glob(base_path('database/migrations/*.php')) as $file) {
        $source = PhpSource::withoutComments((string) file_get_contents($file));

        if (! preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $blocks, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($blocks[1] as $i => [$table, $offset]) {
            $end = $blocks[1][$i + 1][1] ?? strlen($source);
            $block = substr($source, $offset, $end - $offset);

            // ->unique(['a', 'b']) / ->index('a') / ->foreign(['a']) with no second argument.
            preg_match_all('/->(unique|index|foreign)\(\s*(\[[^\]]*\]|[\'"][a-z0-9_]+[\'"])\s*\)/', $block, $calls, PREG_SET_ORDER);
            foreach ($calls as $call) {
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $call[2], $cols);
                $examined++;
                $name = $table.'_'.implode('_', $cols[1]).'_'.$call[1];
                if (strlen($name) > 64) {
                    $overruns[] = basename($file).": {$name} (".strlen($name).')';
                }
            }

            // foreignId('col')…->constrained(…) with no name derives `{table}_{col}_foreign`.
            preg_match_all('/foreignId\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)[^;]*?->constrained\(/', $block, $fks, PREG_SET_ORDER);
            foreach ($fks as $fk) {
                $examined++;
                $name = "{$table}_{$fk[1]}_foreign";
                if (strlen($name) > 64) {
                    $overruns[] = basename($file).": {$name} (".strlen($name).')';
                }
            }
        }
    }

    return [$overruns, $examined];
}

it('keeps every derived index name within MySQL\'s 64-character identifier limit', function () {
    [$overruns, $examined] = migrationIndexOverruns();

    // Non-vacuity: the tree has hundreds of derived index names; a sweep finding a handful has
    // stopped reading migrations.
    expect($examined)->toBeGreaterThan(200);
    expect($overruns)->toBe([], "\n".implode("\n", $overruns));
});

it('would have caught the point-18 migration as first written', function () {
    // The exact shape that shipped on 2026-09-12, computed the way Laravel names it.
    $name = 'fixed_asset_transfer_legs_'.implode('_', ['fixed_asset_transfer_id', 'direction']).'_unique';

    expect(strlen($name))->toBeGreaterThan(64);
});
