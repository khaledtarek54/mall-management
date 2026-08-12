<?php

use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Admin\Resources\Leases\Schemas\LeaseForm;
use App\Support\DerivedFields;
use Illuminate\Support\Facades\File;

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

/*
|--------------------------------------------------------------------------
| "Is there anything LEFT?" — a question a list of known groups cannot answer
|--------------------------------------------------------------------------
| GROUPS only finds what it already knows about, so it answers "are the two things I listed still
| handled?" and never "is there anything else?". That gap was not hypothetical: DF-05 sat on the
| roadmap claiming four remaining derivable pairs, and ALL FOUR were false — two have no field to
| derive into, one is already derived in a model hook, and one is not a form field at all. The row
| had been carried as outstanding work on the strength of a plausible guess.
|
| So the scan discovers candidates instead of trusting a list, and every candidate must carry a
| verdict. A new form shipping a start + term + end triple with no derivation fails the build
| instead of waiting to be noticed.
*/

it('has a verdict for every schema that looks like it carries a derivable pair', function () {
    $unclassified = [];

    foreach (File::allFiles(base_path('app/Filament')) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());

        if (DerivedFields::candidatesIn($source) === []) {
            continue;
        }

        // Already answered by the precise half of the registry.
        if (DerivedFields::groupsExposedBy($source) !== []) {
            continue;
        }

        if (array_key_exists($relative, DerivedFields::CANDIDATE_VERDICTS)) {
            continue;
        }

        $unclassified[] = $relative;
    }

    expect($unclassified)->toBe([], implode("\n", [
        'These schemas expose a start date plus an end date or a duration, and nothing says whether',
        'the relationship is derived:',
        '  '.implode("\n  ", $unclassified),
        '',
        'Derive it, or record the verdict in App\Support\DerivedFields::CANDIDATE_VERDICTS.',
    ]));
});

it('gives every verdict a known value and a real reason', function () {
    foreach (DerivedFields::CANDIDATE_VERDICTS as $path => $meta) {
        expect(file_exists(base_path($path)))->toBeTrue("{$path} no longer exists");
        expect(in_array($meta['verdict'], ['DERIVES', 'NO_TARGET', 'INDEPENDENT'], true))
            ->toBeTrue("{$path} has unknown verdict '{$meta['verdict']}'");
        // "we did not get to it" is a roadmap row, not a verdict.
        expect(strlen($meta['note']))->toBeGreaterThan(40, "{$path} needs a real reason");
    }
});

it('still finds the candidates it is meant to be watching', function () {
    // The scan is regex over source. A rename in Filament, or a schema switching to a helper that
    // builds its inputs, would silently return zero candidates and every assertion above would pass
    // for the wrong reason. This is the control.
    $found = 0;

    foreach (File::allFiles(base_path('app/Filament')) as $file) {
        if (DerivedFields::candidatesIn((string) file_get_contents($file->getPathname())) !== []) {
            $found++;
        }
    }

    expect($found)->toBeGreaterThanOrEqual(count(DerivedFields::CANDIDATE_VERDICTS));
});

it('does not classify a schema as both derived and merely surveyed', function () {
    // CANDIDATE_VERDICTS is the coarse net; DERIVED and EXEMPT are the precise statements. A schema
    // in both would let a real derivation be quietly downgraded to "INDEPENDENT — nothing to do".
    $precise = collect(array_merge(array_keys(DerivedFields::DERIVED), array_keys(DerivedFields::EXEMPT)))
        ->map(fn (string $class) => str_replace('\\', '/', $class))
        ->all();

    foreach (array_keys(DerivedFields::CANDIDATE_VERDICTS) as $path) {
        foreach ($precise as $class) {
            expect(str_contains($path, class_basename(str_replace('/', '\\', $class))))
                ->toBeFalse("{$path} is classified twice");
        }
    }
});
