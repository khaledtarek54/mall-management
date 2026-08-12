<?php

use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Admin\Resources\Leases\Schemas\LeaseForm;
use App\Support\DerivedFields;

/**
 * A field an operator can work out from its neighbours must be worked out FOR them.
 *
 * Wiring one form fixes one form. This gate exists because the next person adding a screen with the
 * same field names will not know `App\Support\DerivedFields` exists, and their form will look
 * finished — which is exactly how `commencement_date`, `term_months` and `expiry_date` came to be
 * three independent inputs on the lease form, letting a lease be saved as "36 months" spanning
 * twelve.
 *
 * **What this gate proves, and what it deliberately does not.** It proves COVERAGE: every schema
 * that exposes a full registered group is classified as derived or exempt, and every "derived"
 * claim names a test file that exists. It does not prove the derivation WORKS — that is behaviour,
 * and a grep over `afterStateUpdated` would be one refactor away from useless (the same reasoning
 * `DerivedMoney` gives for not grepping forms). The behaviour is proved by driving the real
 * components in `DerivedDateFieldsTest` and `LeaseImportExecutesTest`.
 */
function derivableSchemaFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $files[$file->getPathname()] = file_get_contents($file->getPathname());
    }

    return $files;
}

/** class-string for a file under app/Filament, from its path. */
function classForFilamentFile(string $path): string
{
    return 'App\\Filament\\'.str_replace(
        ['/', '.php'],
        ['\\', ''],
        ltrim(str_replace(app_path('Filament'), '', $path), '/'),
    );
}

it('classifies every schema that exposes a derivable field group', function () {
    $unclassified = [];

    foreach (derivableSchemaFiles() as $path => $source) {
        $groups = DerivedFields::groupsExposedBy($source);

        if ($groups === []) {
            continue;
        }

        $class = classForFilamentFile($path);

        foreach ($groups as $group) {
            $isDerived = isset(DerivedFields::DERIVED[$class][$group]);
            $isExempt = isset(DerivedFields::EXEMPT[$class][$group]);

            if (! $isDerived && ! $isExempt) {
                $unclassified[] = "{$class} exposes '{$group}' (".implode(' + ', DerivedFields::GROUPS[$group]['fields']).')';
            }
        }
    }

    expect($unclassified)->toBe([], implode("\n", array_merge(
        ['These schemas let an operator type every field of a derivable group without deriving it:'],
        $unclassified,
        ['', 'Wire the derivation and register it in App\Support\DerivedFields::DERIVED,'],
        ['or add it to ::EXEMPT with a reason why the fields are genuinely independent.'],
    )));
});

it('names a real test for every derivation it claims', function () {
    // A registry entry that says "proved by X" while X does not exist is worse than no entry: it
    // reads as coverage and answers for nothing.
    $missing = [];

    foreach (DerivedFields::DERIVED as $class => $groups) {
        foreach ($groups as $key => $entry) {
            if (! file_exists(base_path($entry['test']))) {
                $missing[] = "{$class}.{$key} names {$entry['test']}, which does not exist";
            }

            if (trim($entry['note']) === '') {
                $missing[] = "{$class}.{$key} has no note saying what derives from what";
            }
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('gives every exemption a reason', function () {
    $unreasoned = [];

    foreach (DerivedFields::EXEMPT as $class => $groups) {
        foreach ($groups as $group => $reason) {
            // Length, because a one-word reason is a shrug. The bar is that someone reading it a
            // year later can tell whether it still holds.
            if (strlen(trim($reason)) < 40) {
                $unreasoned[] = "{$class}.{$group}";
            }
        }
    }

    expect($unreasoned)->toBe([], 'These exemptions need a real reason: '.implode(', ', $unreasoned));
});

it('registers only classes and groups that exist', function () {
    // A registry that has drifted from the code is worse than none: it goes green over a form that
    // was renamed away, while the renamed one sits unclassified.
    $stale = [];

    foreach ([DerivedFields::DERIVED, DerivedFields::EXEMPT] as $registry) {
        foreach ($registry as $class => $groups) {
            if (! class_exists($class)) {
                $stale[] = "{$class} no longer exists";

                continue;
            }

            foreach (array_keys($groups) as $key) {
                $group = is_array($groups[$key]) ? ($groups[$key]['group'] ?? $key) : $key;

                if (! isset(DerivedFields::GROUPS[$group])) {
                    $stale[] = "{$class} names group '{$group}', which is not registered";
                }
            }
        }
    }

    expect($stale)->toBe([], implode("\n", $stale));
});

it('still sees the schemas it is meant to be watching', function () {
    // The gate's own smoke test. If the scan stops matching — a Filament upgrade renaming
    // `TextInput::make`, a form moving to a builder — every assertion above passes over an empty
    // set and the gate silently protects nothing.
    $found = [];

    foreach (derivableSchemaFiles() as $path => $source) {
        foreach (DerivedFields::groupsExposedBy($source) as $group) {
            $found[] = classForFilamentFile($path).":{$group}";
        }
    }

    expect($found)->toContain(
        LeaseForm::class.':lease_term',
        InvoiceForm::class.':invoice_due',
    );
});
