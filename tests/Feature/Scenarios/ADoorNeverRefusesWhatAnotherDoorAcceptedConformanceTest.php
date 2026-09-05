<?php

/**
 * **A FORM NEVER REFUSES WHAT THE IMPORTER BESIDE IT ACCEPTED.**
 *
 * SW-243: `TenantForm` capped `name` at 100, `email` at 150 and `phone` at 20 while
 * `TenantImporter` accepted 200, 255 and 50 — all inside the columns. So a tenant imported from a
 * migrating operator's own file with an ordinary `+20 (2) 2735-1234 ext 402` could never be saved
 * again from its own Edit page: the form refused a field nobody had touched, with a length message
 * about data the system itself had accepted. It was fixed with one constant (`Tenant::FIELD_MAX`)
 * and deliberately left ungated, because the obvious door derivation fires on noise.
 *
 * This is the check done by COLUMN instead of by guessing at doors, and it found one more that no
 * amount of reading either file would have shown: **`LedgerAccountForm::code` capped at 20 while
 * `LedgerAccountImporter` deliberately allows 32** — under a comment saying the length is not
 * constrained to the shipped chart's width while the 8-vs-10-digit question is open with the
 * accountant. The form contradicted its own importer's stated reasoning, on the one register a
 * migrating operator is certain to import: an imported chart account with a longer code could
 * never be edited again.
 *
 * **The other half of this rule needs a real database and lives in `tests/Mysql`.** A door WIDER
 * than its column validates a value the INSERT then refuses, and sqlite cannot see it: Laravel's
 * sqlite grammar emits a bare `varchar` with no length, so a 32-character national ID imported
 * into a `varchar(20)` is perfectly green here and fatal on MySQL. See
 * `tests/Mysql/FieldWidthsOnMysqlTest`, which sits beside the `ValueSets` width check that exists
 * for exactly the same reason.
 *
 * **What this does not prove:** that a door stating no limit at all is safe. Twenty-two importer
 * columns bind a `varchar` with no `max:` rule and every one is a classification column already
 * held to `Rule::in(ValueSets::allowed(...))`, which is far stricter than a length — so demanding
 * a `max:` everywhere would fire on twenty-two correct fields to catch nothing, and a gate that
 * fires on noise gets weakened rather than fixed.
 */

use App\Models\Tenant;
use App\Models\Vendor;
use App\Models\VendorContact;
use Tests\Support\FieldWidths;

it('never lets a form refuse what its own importer accepted', function () {
    $narrow = [];
    $compared = 0;
    $formLimits = FieldWidths::formLimits();

    foreach (FieldWidths::importers() as $path => $model) {
        foreach (FieldWidths::chains(file_get_contents($path), ['ImportColumn']) as $column) {
            $max = FieldWidths::maxRuleOf($column['chain']);

            if ($column['name'] === null || $max === null || ! isset($formLimits[$model][$column['name']])) {
                continue;
            }

            foreach ($formLimits[$model][$column['name']] as [$formMax, $at]) {
                $compared++;

                if ($formMax < $max) {
                    $narrow[] = class_basename($model).".{$column['name']}: form {$formMax} < importer {$max}"
                        ."  ({$at}  vs  ".basename($path).":{$column['line']})";
                }
            }
        }
    }

    // The premise. A resolver that quietly stopped resolving models would compare nothing and
    // report a clean sweep, which is how three gates in this project went blind.
    expect($compared)->toBeGreaterThan(10, 'No form field was compared against an importer at all — '
        .'the two sides are resolving to different models.');

    expect($narrow)->toBe([], 'A form refuses what its own importer accepts, so an imported record '
        ."cannot be saved from its Edit page:\n  ".implode("\n  ", $narrow));
});

it('resolves a relation manager to the model it actually writes', function () {
    // The false attribution this sweep exists not to make, and the first version of it did.
    // `ContactsRelationManager` sits under the Vendors resource and writes `VendorContact`;
    // reading it as `Vendor` — its parent resource's model — reported a `vendors.email`
    // divergence for a field that never touches that table.
    expect(FieldWidths::modelFor(base_path('app/Filament/Admin/Resources/Vendors/RelationManagers/ContactsRelationManager.php')))
        ->toBe(VendorContact::class);

    expect(FieldWidths::modelFor(base_path('app/Filament/Admin/Resources/Vendors/Schemas/VendorForm.php')))
        ->toBe(Vendor::class);

    // And a relation manager filed outside any resource directory resolves to NOTHING rather than
    // to a guess — it is mounted by more than one page, so its model is not a fact about its path.
    expect(FieldWidths::modelFor(base_path('app/Filament/Admin/RelationManagers/ChargeScheduleRelationManager.php')))
        ->toBeNull();
});

it('reads a shared width constant rather than skipping it', function () {
    // `Tenant::FIELD_MAX['phone']` is how this project states a width shared by a form and an
    // importer — the SW-243 fix routed five fields through it — so a sweep that only understood
    // integer literals would be blind to the exact fields it was built to watch.
    expect(FieldWidths::maxLengthOf("::make('phone')->tel()->maxLength(Tenant::FIELD_MAX['phone'])"))
        ->toBe(Tenant::FIELD_MAX['phone']);

    expect(FieldWidths::maxRuleOf("::make('phone')->rules(['nullable', 'max:'.Tenant::FIELD_MAX['phone']])"))
        ->toBe(Tenant::FIELD_MAX['phone']);
});
