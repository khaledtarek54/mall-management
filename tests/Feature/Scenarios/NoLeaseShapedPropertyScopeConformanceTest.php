<?php

use Illuminate\Support\Facades\Schema;

/**
 * A document that carries its OWN property must never be scoped through a lease.
 *
 * `invoices.lease_id` was NOT NULL until module 37 introduced the unit owner — a party who buys a
 * shop, trades from it, and holds no lease. Plan 08 §5.2b named the lesson when the column was
 * relaxed: *relaxing a NOT NULL is a change to every inference that column licensed.* Phase 2a acted
 * on it for the four load-bearing sites by denormalising `invoices.asset_id` (and `credit_notes`
 * with it) — and left the READ layer, the collection screens and the guards.
 *
 * Five defects came out of that gap in one day, and every one was the same sentence in a different
 * file — *the lease is the route to the property*:
 *
 *   - another property's credit note counted in this property's monthly close
 *   - a unit owner in arrears was not "delinquent"
 *   - an owner's credit note vanished from the register entirely
 *   - an owner's assessments were missing from their own invoices tab
 *   - the credit-note cross-property guard was SKIPPED for owner documents, so one mall's note
 *     settled another mall's assessment and the contra-revenue landed in the wrong books
 *
 * None failed loudly. An unbilled owner produces no error, a missing row looks like no data, and a
 * skipped guard looks like a successful save. This gate makes the next one fail at build time.
 *
 * **Scope, deliberately narrow.** It flags only a `whereHas` that hops through `lease` *and*
 * constrains `asset_id` — the property inference — on a model that carries its own `asset_id`. An
 * eager load, a display column (`lease.unit.code`) or a genuine lease-domain filter is untouched,
 * because those are not claims about which property a row belongs to.
 */

/** Models that carry their own `asset_id` AND a nullable `lease_id` — the shape this rule is about. */
function selfScopedDocumentTables(): array
{
    return array_values(array_filter(
        ['invoices', 'credit_notes'],
        fn (string $table) => Schema::hasColumn($table, 'asset_id') && Schema::hasColumn($table, 'lease_id'),
    ));
}

it('scopes a self-scoped document by its own property column, never through a lease', function () {
    // The premise the whole gate rests on — if these ever stop carrying their own property, the
    // rule below is meaningless and should fail loudly rather than pass vacuously.
    expect(selfScopedDocumentTables())->toBe(['invoices', 'credit_notes']);

    $offenders = [];

    foreach (['app'] as $dir) {
        $base = base_path($dir);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $lines = file($path);

            foreach ($lines as $i => $line) {
                // A lease hop that constrains asset_id, on the same line or the next — the property
                // inference. `leases.` (plural, on a Tenant or Asset) is a different relationship
                // and legitimately reaches units, so it is excluded.
                if (! preg_match("/whereHas\(\s*'lease(\.|')/", $line)) {
                    continue;
                }

                $window = $line.($lines[$i + 1] ?? '');
                if (! str_contains($window, 'asset_id')) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $path).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([], "These infer a document's PROPERTY by hopping through its lease, on a model that carries its own `asset_id`. A lease-less document — every unit-owner assessment and every credit note against one — falls out of the result set silently:\n  - ".implode("\n  - ", $offenders)."\n\nScope on the row's own `asset_id` (or `TenantScope::applyTo()`), which is what phase 2a denormalised it for.");
});
