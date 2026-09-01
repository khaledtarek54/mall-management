<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\SavedReport;
use App\Support\Filament\SavedColumnLayout;
use App\Support\ReportCatalogue;
use App\Support\ReportParameters;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * "Save this view" — the header action that turns a set of filters into a named report.
 *
 * A report's filters were not rememberable. "AR ageing as at last month-end for Atriom Walk" was
 * six clicks, and the operator who ran it on the third of every month rebuilt it on the third of
 * every month.
 *
 * The action snapshots whatever the page is currently carrying, through
 * {@see ReportParameters::snapshot()} — so a report that grows a filter has it saved without anyone
 * registering it here.
 *
 * **Pages opt in by using this trait and calling {@see saveViewAction()} in their header actions.**
 * Not injected globally through a render hook, though that was the tempting alternative: a hook can
 * paint a button onto every page but cannot carry a modal, and a "save" that cannot ask for a name
 * produces nineteen views called "Untitled".
 */
trait SavesReportViews
{
    /**
     * The action, for a page's `getHeaderActions()`.
     *
     * Hidden on a page that is not catalogued — a saved view keys on the catalogue entry, so
     * offering it where there is none would create a row nothing could open.
     */
    protected function saveViewAction(): Action
    {
        return Action::make('saveReportView')
            ->label(__('admin.report_hub.save_view'))
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->visible(fn (): bool => Auth::check() && $this->reportCatalogueKey() !== null)
            // The UI half is `visible()`; this is the gate. Every write action in this codebase
            // carries both, because hidden-implies-disabled is an upstream implementation detail
            // and `->authorize()` is a stated intent.
            ->authorize(fn (): bool => Auth::check() && $this->reportCatalogueKey() !== null)
            ->modalHeading(__('admin.report_hub.save_view'))
            ->modalDescription(__('admin.report_hub.save_view_description'))
            ->schema([
                TextInput::make('name')
                    ->label(__('admin.fields.name'))
                    ->required()
                    ->maxLength(120)
                    // Prefilled with the report's own name, because the commonest saved view is
                    // "this report, the way I always run it" and typing its title again is friction
                    // for nothing.
                    ->default(fn () => $this->getTitle()),
                Toggle::make('is_shared')
                    ->label(__('admin.report_hub.share_view'))
                    ->helperText(__('admin.report_hub.share_view_help')),
            ])
            ->action(function (array $data): void {
                $key = $this->reportCatalogueKey();

                abort_unless(Auth::check() && $key !== null, 403);

                SavedReport::create([
                    'report' => $key,
                    'name' => $data['name'],
                    // Whatever the page is carrying right now — not a re-read of defaults.
                    // The parameters AND the column layout. `ReportParameters::snapshot()` reads
                    // the page's own public scalar properties and deliberately excludes
                    // trait-provided ones, so Filament's `$tableColumns` is invisible to it — a
                    // saved report remembered its filters and reset its columns.
                    'parameters' => ReportParameters::snapshotForSavedView($this) + $this->savedReportColumnLayout(),
                    'user_id' => Auth::id(),
                    'is_shared' => (bool) ($data['is_shared'] ?? false),
                ]);

                Notification::make()
                    ->title(__('admin.report_hub.view_saved'))
                    ->body(__('admin.report_hub.view_saved_body'))
                    ->success()
                    ->send();
            });
    }

    /**
     * This report's column layout, or nothing when the page has no table.
     *
     * Several catalogued "reports" are not tables at all — the workflow diagram, the occupancy map —
     * and asking them for a column state would fatal. The guard is `method_exists`, not a list of
     * page classes: a list would be one more thing to keep in step with the catalogue.
     *
     * @return array<string, mixed>
     */
    protected function savedReportColumnLayout(): array
    {
        if (! method_exists($this, 'getDefaultTableColumnState')) {
            return [];
        }

        return SavedColumnLayout::capture($this->tableColumns ?? []);
    }

    /**
     * Apply the column layout of the saved report named in the URL, if there is one.
     *
     * Same seam as a resource list's saved view, and for the same reason: a layout is far too big
     * for a query string, so the link carries `?savedReport={id}` and the page reads the columns
     * back. Livewire calls `booted{TraitName}` after mount, and either order converges —
     * `initTableColumnManager()` returns early when `$tableColumns` is already filled, and
     * re-applying an already-applied state is idempotent.
     *
     * Silent on a missing or unreadable id: it names a display preference, not a record, so a
     * deleted bookmark opens the report on its default columns rather than refusing the page.
     */
    public function bootedSavesReportViews(): void
    {
        $id = request()->query('savedReport');

        if (! is_numeric($id) || ! Auth::check() || ! method_exists($this, 'getDefaultTableColumnState')) {
            return;
        }

        $key = $this->reportCatalogueKey();

        $saved = $key === null ? null : SavedReport::query()
            ->whereKey((int) $id)
            ->where('report', $key)
            ->visibleTo(Auth::id())
            ->first();

        if ($saved === null) {
            return;
        }

        $order = SavedColumnLayout::orderFrom($saved->parameters);

        $this->applyTableColumnManager(
            SavedColumnLayout::rebuild(
                $this->getDefaultTableColumnState(),
                SavedColumnLayout::togglesFrom($saved->parameters),
                $order,
            ),
            wasReordered: $order !== [],
        );
    }

    /** This page's catalogue key, or null when it is not a catalogued report. */
    protected function reportCatalogueKey(): ?string
    {
        return ReportCatalogue::REPORTS[static::class]['key'] ?? null;
    }
}
