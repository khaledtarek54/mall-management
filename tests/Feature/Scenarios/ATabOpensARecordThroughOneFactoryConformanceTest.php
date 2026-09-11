<?php

use App\Filament\Actions\OpenRecordAction;
use App\Support\Filament\RowClickTarget;
use Tests\Support\ActionStrips;

/*
|--------------------------------------------------------------------------
| A tab's "Open" is ONE factory (2026-09-11)
|--------------------------------------------------------------------------
| Twelve relation managers each hand-wrote the act that takes an operator from a row on a tab to
| the record's own page — in four shapes: one with no visibility gate at all (a viewer was offered
| a link into a 403), one gating only on the link resolving, six gating on `canEdit` alone (a
| viewer lost the link on exactly the tabs where the View page is the fallback), four through
| `PropertyLink`, and one labelled "View" — plus two CELL links built the same way. `OpenRecordAction`
| is the one definition; this keeps it the only one.
|
| Two shapes are refused under `app/Filament`:
|   - a bare `Action::make('open')` outside the factory — the name is the factory's;
|   - a row action whose `->url()` resolves a RESOURCE's `edit`/`view` page for the row — an Open
|     wearing another name (`AssetUnitsRelationManager` did it as an `EditAction` with a URL).
|
| `RendersNotificationCentre::open` is registered: it follows a notification's DEEP LINK, which is
| not a record page and has its own resolver (`NotificationLink`).
*/

const OPEN_LINK_EXEMPT = [
    'app/Filament/Concerns/RendersNotificationCentre.php' => 'Opens a notification\'s deep link — resolved by NotificationLink, not a resource page; the row is a bell entry, not a record.',
];

/** Comment-stripped source, so a docblock naming the pattern is not a hit. */
function openLinkSource(string $file): string
{
    $source = (string) file_get_contents($file);
    $out = $source;

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $at = strpos($out, $token[1]);

            if ($at !== false) {
                $out = substr_replace($out, str_repeat(' ', strlen($token[1])), $at, strlen($token[1]));
            }
        }
    }

    return $out;
}

it('declares no hand-written open act and no row link into a resource page outside the factory', function () {
    $offenders = [];
    $factoryUses = 0;
    $relative = fn (string $f): string => str_replace(base_path().'/', '', $f);

    foreach (ActionStrips::sources() as $file) {
        $rel = $relative($file);

        if ($rel === $relative((new ReflectionClass(OpenRecordAction::class))->getFileName())) {
            continue;
        }

        $source = openLinkSource($file);
        $factoryUses += preg_match_all('/OpenRecordAction::make\(/', $source);

        if (array_key_exists($rel, OPEN_LINK_EXEMPT)) {
            continue;
        }

        if (preg_match("/Action::make\('".OpenRecordAction::NAME."'\)/", $source)) {
            $offenders[] = "{$rel} declares Action::make('open') — use OpenRecordAction::make(Resource::class)";
        }

        // A record-page URL built by hand inside a relation manager — on a row action OR a cell
        // (`AssetStaffRelationManager` linked the email column that way). A RESOURCE table's own
        // EditAction/ViewAction resolve their page without `getUrl()`, so inside a tab this shape
        // only ever appears where somebody rebuilt Open; `OpenRecordAction::urlFor()` is the
        // resolver for a cell, `::make()` for a row.
        if (str_ends_with($rel, 'RelationManager.php')
            && preg_match("/Resource::getUrl\(\s*'(edit|view)'\s*,\s*\[\s*'record'\s*=>/", $source)) {
            $offenders[] = "{$rel} builds a record-page URL by hand — OpenRecordAction::make() on a row, ::urlFor() on a cell";
        }
    }

    // Premise: the factory is in use across the tabs (twelve on the day it was written).
    expect($factoryUses)->toBeGreaterThanOrEqual(10, 'OpenRecordAction is composed on far fewer tabs than expected — the sweep is reading the wrong tree.');

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

it('keeps every exemption pointing at a file that still declares the act', function () {
    foreach (OPEN_LINK_EXEMPT as $rel => $reason) {
        expect(is_file(base_path($rel)))->toBeTrue("{$rel} no longer exists — drop the exemption.");
        expect(openLinkSource(base_path($rel)))->toContain("Action::make('".OpenRecordAction::NAME."')");
        expect(strlen($reason))->toBeGreaterThan(40);
    }
});

it('is read by the row click, after edit and view', function () {
    // The half no hand-written copy had: `RowClickTarget` resolves a tab's `open` action as the
    // row's destination, so the button and the click cannot disagree. Edit and view stay first —
    // a register declares those, and they decide the register's row.
    expect(RowClickTarget::ORDER)->toBe(['edit', 'view', OpenRecordAction::NAME]);
});
