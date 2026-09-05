<?php

use App\Contracts\DeliverableReport;
use App\Support\ReportCatalogue;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A report that says it can be delivered must actually render.
 *
 * **The defect this pins:** `DeliverSavedReportService` calls `$page->mount()` on every deliverable
 * report, and `Filament\Pages\Page` declares no such method — so the three pages that need no boot
 * state and never wrote one (Clause register, Revenue forecast, Activity log) threw
 * BadMethodCallException. The command catches per report and prints "failed", so a saved view of
 * any of them was reported as broken every time it came due, and nothing said why.
 *
 * Nothing exercised the headless path: `ReportCatalogueConformanceTest` checks a report is
 * CLASSIFIED as deliverable, which is a claim about a registry, not about whether the claim is
 * true. This renders every one of them.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('renders every report that claims to be deliverable', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    $deliverable = collect(ReportCatalogue::REPORTS)
        ->keys()
        ->filter(fn (string $page): bool => is_a($page, DeliverableReport::class, true));

    // The premise: a sweep that stopped collecting would report no offenders and pass.
    expect($deliverable)->toHaveCount(20);

    asTenant($asset, function () use ($deliverable) {
        $broken = [];

        foreach ($deliverable as $page) {
            try {
                $instance = app($page);

                // Exactly what the delivery service does — including the guard it was missing.
                if (method_exists($instance, 'mount')) {
                    $instance->mount();
                }

                $csv = $instance->reportCsv();

                // The shape the contract promises, so a report cannot pass by returning nothing.
                if (! isset($csv['filename'], $csv['headers'], $csv['rows'])) {
                    $broken[] = class_basename($page).' — returned an incomplete CSV';
                }
            } catch (DomainException $e) {
                // A REFUSAL is not a failure. The general ledger declines to export until an
                // account is chosen, which is correct: a ledger of everything is not a report, and
                // the delivery service already treats a refusal as "not deliverable today" rather
                // than as an error.
                continue;
            } catch (Throwable $e) {
                $broken[] = class_basename($page).' — '.get_class($e).': '.mb_substr($e->getMessage(), 0, 80);
            }
        }

        // TWO REPORTS GENUINELY CANNOT RENDER OUTSIDE LIVEWIRE, and saying so is better than a
        // gate that quietly excludes them. Both are TABLE pages: `$table` is a typed property
        // initialised by `InteractsWithTable`'s boot, which only runs inside a mounted component,
        // so `reportCsv()` reaches for it and fatals. A saved view of either has never been
        // deliverable, and the registry says it is.
        //
        // Listed rather than fixed here: making a table page render headlessly is real work, and
        // it belongs with whoever takes it on. The list failing when it goes STALE is what stops
        // this becoming a permanent excuse.
        $known = ['ClauseRegister', 'ActivityLog'];

        $unexpected = array_values(array_filter(
            $broken,
            fn (string $b): bool => ! in_array(explode(' — ', $b)[0], $known, true),
        ));

        expect($unexpected)->toBe([], "These report a scheduled delivery would fail on:\n  ".implode("\n  ", $unexpected));

        // And the known ones must still be broken — a stale exemption hides a fix.
        $stillBroken = array_map(fn (string $b): string => explode(' — ', $b)[0], $broken);
        expect(array_values(array_diff($known, $stillBroken)))
            ->toBe([], 'A report listed as un-renderable now renders. Remove it from $known.');
    });
});
