<?php

/*
|--------------------------------------------------------------------------
| Conformance gate — no label is manufactured from a column value
|--------------------------------------------------------------------------
| `Str::headline($state)` turns `in_progress` into "In progress". It is a
| plausible-looking one-liner that ships an English label into a bilingual UI,
| and it is invisible to every check this project already has:
|
|   - the STATIC key sweep looks for `__()` calls whose keys are missing from a
|     catalogue. There is no key here, so there is nothing to miss.
|   - the RUNTIME sweep looks for raw `admin.…` strings rendered on a page.
|     "In progress" is not a raw key, it is a perfectly rendered English word.
|
| So it reads as translated from every angle except an Arabic-speaking
| operator's. Ten call sites shipped that way across owner requests and
| marketing budgets, including one — the owner-request STATUS — sitting next to
| an `admin.owner_requests.statuses` catalogue that already existed, in both
| languages, and was already used by the same file's set-status action.
|
| The same applies to an options array written in English inline
| (`['open' => 'Open', 'closed' => 'Closed']`), which is the same mistake with
| the helper spelled out.
*/

use App\Models\MarketingSpend;
use App\Models\OwnerRequest;
use Illuminate\Support\Str;

/** Every PHP file under app/Filament. */
function filamentSources(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('never manufactures a user-facing label out of a column value', function () {
    $offenders = [];

    foreach (filamentSources() as $path) {
        $source = file_get_contents($path);

        // `Str::headline(...)` / `->headline()` anywhere in the admin or portal UI is a label the
        // operator reads, and it is English by construction.
        if (preg_match('/Str::headline\s*\(/', $source)) {
            $offenders[] = Str::after($path, base_path().'/');
        }
    }

    expect($offenders)->toBe([],
        'These build a label from a column value instead of reading one from the catalogue, so the '
        ."Arabic UI shows English words that no translation check can see:\n  "
        .implode("\n  ", $offenders)
        ."\n\nUse __('admin.enums.<enum>.<value>') (or the module's own map, e.g. "
        .'admin.owner_requests.statuses) — and check for an existing catalogue before adding one.');
});

it('keeps exactly one catalogue per enum, not one per screen', function () {
    // The duplicate this gate was written after: a second owner-request status map was added under
    // `enums` while `owner_requests.statuses` already existed and was already in use. Two truths
    // about the same five words drift the moment one of them is reworded.
    $en = require lang_path('en/admin.php');

    $statusMaps = array_filter([
        'owner_requests.statuses' => $en['owner_requests']['statuses'] ?? null,
        'enums.owner_request_status' => $en['enums']['owner_request_status'] ?? null,
    ]);

    expect(array_keys($statusMaps))->toBe(['owner_requests.statuses'],
        'Owner-request statuses are catalogued in more than one place: '
        .implode(', ', array_keys($statusMaps)));
});

it('labels every value of the enums those screens actually render', function () {
    // A catalogue that covers four of five values fails on exactly the fifth row, which is the one
    // nobody has in their test data.
    $cases = [
        [OwnerRequest::STATUSES, 'admin.owner_requests.statuses'],
        [OwnerRequest::PRIORITIES, 'admin.owner_requests.priorities'],
        [MarketingSpend::CATEGORIES, 'admin.enums.marketing_spend_category'],
        [['open', 'closed'], 'admin.enums.marketing_budget_status'],
    ];

    foreach ($cases as [$values, $catalogue]) {
        foreach (['en', 'ar'] as $locale) {
            foreach ($values as $value) {
                $label = __("{$catalogue}.{$value}", [], $locale);

                expect($label)->not->toBe("{$catalogue}.{$value}",
                    "[{$locale}] has no label for {$catalogue}.{$value} — the screen will render the raw key.");
            }
        }
    }
});
