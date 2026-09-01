<?php

/*
|--------------------------------------------------------------------------
| One act, one declaration (2026-09-01)
|--------------------------------------------------------------------------
| `EditInvoice` composed `InvoiceActions::all()` — which defines `regeneratePaymentLink` — AND kept
| a second, inline copy of the same act. Both rotated the same token, so neither was wrong; what
| was wrong is that the operator read the same DESTRUCTIVE button twice in one header, with nothing
| to say which was which. Reported from the panel.
|
| It is invisible from either file. Each is correct on its own, and `cacheAction()` keys by name —
| so `mountAction('regeneratePaymentLink')` resolves, `assertActionVisible` passes, and the two
| tests that drive that exact act on that exact page were green throughout. **Only the rendered
| header shows two.**
|
| That is the shape `App\Filament\Admin\Actions\*Actions` exists to prevent — one definition,
| several surfaces — and the shape it makes newly possible, because composing a registry does not
| stop a page ALSO declaring one of its members. Nineteen surfaces compose a registry today; this
| sweeps every one of them.
*/

it('never declares an act a registry it composes has already defined', function () {
    // A closure, not a file-scope `function`: two test files declaring one helper name is a fatal
    // redeclaration during collection that exits the suite 255 with NO output, and `--parallel`
    // hides it. This project has had that four times; `rglob` in this file's first draft would
    // have been the fifth (it already exists in ImportIsAdminOnlyTest).
    $declaredIn = function (string $body): array {
        preg_match_all("/Action::make\\('([^']+)'\\)/", $body, $matches);

        return array_values(array_unique($matches[1]));
    };

    $registries = [];

    foreach (glob(app_path('Filament/Admin/Actions/*Actions.php')) as $file) {
        $registries[basename($file, '.php')] = $declaredIn((string) file_get_contents($file));
    }

    // Premise: the sweep is worthless if there are no registries to compose.
    expect($registries)->not->toBeEmpty();

    $offenders = [];
    $composed = 0;

    foreach (filamentSources() as $file) {
        $body = (string) file_get_contents($file);

        foreach ($registries as $registry => $names) {
            // A surface composes a registry by name — `::all()`, `::grouped()`, `::forOwner()`.
            // Reading the CALL rather than the import, because an import proves nothing is used.
            if (! preg_match('/\b'.preg_quote($registry, '/').'::(all|grouped|forOwner|only)\(/', $body)) {
                continue;
            }

            $composed++;

            $clash = array_values(array_intersect($declaredIn($body), $names));

            if ($clash !== []) {
                $offenders[] = str_replace(base_path().'/', '', $file)
                    .' re-declares '.$registry.': '.implode(', ', $clash);
            }
        }
    }

    expect($offenders)->toBe([], "These render the same act twice — once from the registry they\n"
        ."compose, once from their own copy:\n  ".implode("\n  ", $offenders));

    // Vacuity guard. A registry renamed out of this glob, or a `::all()` spelled some new way,
    // would leave the sweep examining nothing and reporting clean — which is how a gate stops
    // gating without anybody noticing.
    expect($composed)->toBeGreaterThan(10, 'The sweep found no composition sites at all.');
});
