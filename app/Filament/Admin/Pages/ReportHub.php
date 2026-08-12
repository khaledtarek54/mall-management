<?php

namespace App\Filament\Admin\Pages;

use App\Models\SavedReport;
use App\Support\ReportCatalogue;
use App\Support\ReportParameters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Every report in one place — what the system can tell you, and what each answer is for.
 *
 * The reports were never the missing part. There are nineteen of them, and they were scattered
 * across five sidebar groups with nothing anywhere listing them: an operator who had not been shown
 * a report did not know it existed, and one shown it once had to remember which group it lived
 * under. This is the index, which is what the benchmark systems' single Reports menu actually is.
 *
 * **The description is the feature.** A list of nineteen titles is a menu. What makes it usable is
 * each line saying which question the report answers, so somebody finds the right one without
 * opening five — "AR ageing" and "AR ageing by charge type" are indistinguishable as titles and
 * obvious as sentences.
 *
 * **Listed exactly when it can be opened.** Each page answers `canAccess()` for itself and the hub
 * asks it, so an operator never meets a link that refuses them. `ReportCatalogue` holds the
 * classification and a conformance gate fails the build on an unclassified page — the person adding
 * the twentieth report will not know any of this exists, and their screen would otherwise be
 * reachable only by URL.
 *
 * The per-report navigation entries stay where they are. Somebody who knows the leasing group holds
 * the rent roll should keep finding it there; this adds a way in, it does not move the furniture.
 */
class ReportHub extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static string $routePath = 'report-hub';

    protected string $view = 'filament.pages.report-hub';

    public static function getNavigationLabel(): string
    {
        return __('admin.report_hub.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.report_hub.page_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.report_hub.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /**
     * Reachable by anyone who can open at least one report.
     *
     * Not a permission of its own: the hub holds nothing an operator could not already see, and
     * inventing `report_hub.view` would create a way to be refused the index while being allowed
     * every report in it.
     */
    public static function canAccess(): bool
    {
        return Auth::check() && ReportCatalogue::visibleTo() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $records = [];
                $i = 0;

                // Saved views first, because somebody who has one is almost always coming back for
                // it — the catalogue below is for finding a report, this is for re-running one.
                foreach ($this->savedViews() as $view) {
                    $records[] = [
                        'id' => 'v'.$view->id,
                        'saved_report_id' => $view->id,
                        'category' => __('admin.report_hub.saved_views'),
                        'title' => $view->name,
                        'description' => $view->description,
                        'url' => $view->url,
                    ];
                }

                foreach (ReportCatalogue::visibleTo() as $category => $reports) {
                    foreach ($reports as $report) {
                        $records[] = [
                            'id' => 'r'.$i++,
                            'category' => __("admin.report_hub.categories.{$category}"),
                            'saved_report_id' => null,
                            'title' => $report['title'],
                            'description' => $report['description'],
                            'url' => $report['url'],
                        ];
                    }
                }

                return $records;
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.report_hub.report'))
                    ->weight('medium')
                    ->url(fn (array $record): string => $record['url'])
                    ->color('primary')
                    // The description sits under the title rather than in its own column: at this
                    // width a second text column wraps into an unreadable ladder, and the two
                    // belong to each other anyway.
                    ->description(fn (array $record): string => $record['description'])
                    ->wrap()
                    ->searchable(),
            ])
            ->groups([
                Group::make('category')
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['category'])
                    ->getTitleFromRecordUsing(fn (array $record): string => $record['category']),
            ])
            ->defaultGroup('category')
            // An index is one page. Paginating nineteen rows would hide the second half of the
            // thing whose entire purpose is to show what exists.
            ->paginated(false)
            ->recordActions([
                Action::make('deleteSavedView')
                    ->label(__('admin.report_hub.delete_view'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    // Only on a saved view, and only one this operator owns. A SHARED view belongs
                    // to whoever saved it: removing it from under a colleague because it appeared
                    // in your list is not a delete anyone asked for.
                    ->visible(fn (array $record): bool => $this->ownsSavedView($record))
                    ->authorize(fn (array $record): bool => $this->ownsSavedView($record))
                    ->action(function (array $record): void {
                        abort_unless($this->ownsSavedView($record), 403);

                        SavedReport::whereKey($record['saved_report_id'])
                            ->where('user_id', Auth::id())
                            ->delete();

                        Notification::make()->title(__('admin.report_hub.view_deleted'))->success()->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('admin.report_hub.empty'));
    }

    /**
     * This operator's saved views, plus anything the team shared, for reports they can still open.
     *
     * `visibleTo()` on the query is ownership; the `canAccess()` filter below is capability. Both
     * are needed: a shared view must not list a report the reader cannot open, and a view whose
     * report was removed from the catalogue must not render a dead row.
     *
     * @return Collection<int, object>
     */
    protected function savedViews(): Collection
    {
        $pageFor = collect(ReportCatalogue::REPORTS)
            ->mapWithKeys(fn (array $meta, string $page) => [$meta['key'] => $page]);

        return SavedReport::query()
            ->visibleTo(Auth::id())
            ->catalogued()
            ->with('user:id,name')
            ->orderBy('name')
            ->get()
            ->filter(fn (SavedReport $view) => rescue(
                fn () => $pageFor[$view->report]::canAccess(),
                false,
                false,
            ))
            ->map(fn (SavedReport $view) => (object) [
                'id' => $view->id,
                'name' => $view->name,
                'user_id' => $view->user_id,
                // Whose it is, when it is not yours — a shared view with no author reads as
                // something the system produced rather than something a colleague set up.
                // Whose it is, when it is not yours — a shared view with no author reads as
                // something the system produced rather than something a colleague set up. Your own
                // views describe the report instead, which is the more useful line to read.
                'description' => $view->user_id === Auth::id()
                    ? __("admin.report_hub.descriptions.{$view->report}")
                    : __('admin.report_hub.shared_by', ['name' => $view->user?->name ?? '—']),
                'url' => ReportParameters::urlFor($pageFor[$view->report], $view->parameters ?? []),
            ]);
    }

    /** Is this row a saved view belonging to the current operator? */
    protected function ownsSavedView(array $record): bool
    {
        return filled($record['saved_report_id'] ?? null)
            && SavedReport::whereKey($record['saved_report_id'])->where('user_id', Auth::id())->exists();
    }
}
