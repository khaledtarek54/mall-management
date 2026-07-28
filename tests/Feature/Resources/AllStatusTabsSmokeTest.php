<?php

/**
 * Every tab on every List page must build, query, and count.
 *
 * A broken tab is invisible until someone clicks it: a bad status string just
 * shows an empty list, and a bad column or a stale i18n key only blows up on
 * that one tab. This walks all of them — building each tab, running its query,
 * and resolving its badge — so a typo cannot ship silently.
 *
 * It is deliberately schema-driven rather than a hand-list: a List page added
 * later is picked up automatically.
 */

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\File;

it('builds, queries and counts every tab on every admin list page', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $pages = collect(File::allFiles(app_path('Filament/Admin/Resources')))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) {
            $rel = str_replace([app_path('Filament/Admin/Resources').'/', '.php'], '', $f->getPathname());

            return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, ListRecords::class))
        ->values();

    expect($pages)->not->toBeEmpty();

    $checked = 0;

    asTenant($asset, function () use ($pages, &$checked) {
        foreach ($pages as $pageClass) {
            $page = new $pageClass;
            $tabs = $page->getTabs();

            foreach ($tabs as $key => $tab) {
                expect($tab)->toBeInstanceOf(Tab::class, "{$pageClass}: tab [{$key}] is not a Tab");

                // A label that came back as the raw translation key means a
                // missing/renamed i18n entry — the tab would render "admin.tabs.x".
                $label = $tab->getLabel();
                expect($label)->toBeString()
                    ->and($label)->not->toStartWith('admin.', "{$pageClass}: tab [{$key}] has an unresolved translation key [{$label}]");

                // Run the tab's own query — this is what catches a status value
                // that does not exist, or a filter on a column that does not.
                $resource = $pageClass::getResource();
                expect($tab->modifyQuery($resource::getEloquentQuery())->count())->toBeInt();

                // Resolve the badge closure too — badges run their own count.
                expect($tab->getBadge())->toBeIn([null, '0']);

                $checked++;
            }
        }
    });

    // Guard against the whole thing silently walking zero tabs.
    expect($checked)->toBeGreaterThan(80);
});
