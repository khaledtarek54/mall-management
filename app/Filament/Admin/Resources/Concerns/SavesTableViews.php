<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\TableView;
use App\Support\ResourceLink;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * "Save this view" for a resource LIST — the header actions that turn a set of filters into a
 * named, reopenable list.
 *
 * `SavesReportViews` did this for report pages. This is the same idea where the filters actually
 * pile up: Leases carries 12 filters, Invoices 10, tenant requests 9, and "active leases in this
 * mall whose option window shuts this quarter" was five controls rebuilt every morning.
 *
 * ## A view is a URL
 *
 * Saving snapshots the four things a Filament list page binds to the query string — `filters`,
 * `sort`, `search`, `tab` — and applying one is a plain link built by {@see ResourceLink}. There is
 * no second code path that sets Livewire state directly, which matters more than it sounds: the
 * URL path is the one already proved to work end-to-end (`ResourceLinkConformanceTest`), and a
 * saved view therefore inherits its guarantees, including that a URL beats a stale session filter.
 *
 * It also means a saved view is a link an operator can paste to a colleague, and that a shared
 * view opening for someone else re-scopes through that list's own `getEloquentQuery()` — property
 * isolation and RBAC are applied to it exactly as they are to a hand-typed URL.
 *
 * ## Opting in
 *
 * A page uses this trait and spreads {@see savedViewActions()} into `getHeaderActions()`. Not
 * global: the header is the page's own composition, and a resource whose list is a fixed worklist
 * (one that carries no filters worth naming) should not grow a bookmark menu it cannot fill.
 */
trait SavesTableViews
{
    /**
     * The pair of header actions: the saved-view menu, and "save the current view".
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function savedViewActions(): array
    {
        return [
            $this->savedViewsMenuAction(),
            $this->saveTableViewAction(),
        ];
    }

    /**
     * The stable key a view is stored against — the resource SLUG, matching the URL segment.
     *
     * Never the class name: a namespace move would orphan every saved row, which is the same
     * reasoning `saved_reports` uses for storing a catalogue key.
     */
    protected function savedViewResourceKey(): string
    {
        return static::getResource()::getSlug();
    }

    /** The views this user may open here: their own, plus anything shared with the team. */
    protected function savedViewsForThisList(): Collection
    {
        if (! Auth::check()) {
            return collect();
        }

        return TableView::query()
            ->where('resource', $this->savedViewResourceKey())
            ->visibleTo(Auth::id())
            ->orderBy('name')
            ->get();
    }

    /**
     * A menu of saved views, each a link; plus a delete for the ones this user owns.
     *
     * Hidden entirely when there is nothing saved — an empty dropdown is worse than no dropdown,
     * and the "Save view" button beside it is already the discoverable way in.
     */
    protected function savedViewsMenuAction(): ActionGroup
    {
        $views = $this->savedViewsForThisList();

        $actions = $views->map(function (TableView $view): Action {
            $isOwn = $view->user_id === Auth::id();

            return Action::make('savedView'.$view->id)
                ->label($view->name)
                ->icon($isOwn ? 'heroicon-o-bookmark' : 'heroicon-o-user-group')
                // A link, not a state mutation — see the class docblock. `tableView` carries the
                // id so the COLUMNS travel with it too: a layout is far too big for a query string,
                // and this keeps a saved view a single pasteable URL rather than growing a second
                // code path that sets Livewire state directly.
                ->url(ResourceLink::index(
                    static::getResource(),
                    filters: $view->queryParameters()['filters'] ?? [],
                    sort: $view->queryParameters()['sort'] ?? null,
                    search: $view->queryParameters()['search'] ?? null,
                    tab: $view->queryParameters()['tab'] ?? null,
                    tableView: $view->id,
                ));
        })->all();

        if ($views->isNotEmpty()) {
            $actions[] = $this->manageSavedViewsAction();
        }

        return ActionGroup::make($actions)
            ->label(__('admin.saved_views.menu'))
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => $views->isNotEmpty());
    }

    /** Delete one of this user's own saved views. */
    protected function manageSavedViewsAction(): Action
    {
        return Action::make('deleteSavedView')
            ->label(__('admin.saved_views.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading(__('admin.saved_views.delete'))
            ->modalSubmitActionLabel(__('admin.saved_views.delete'))
            ->schema([
                Select::make('view_id')
                    ->label(__('admin.saved_views.menu'))
                    ->required()
                    // Only the operator's OWN views. A shared view belongs to whoever saved it;
                    // being able to see one is not being able to remove it from under them.
                    ->options(fn (): array => TableView::query()
                        ->where('resource', $this->savedViewResourceKey())
                        ->ownedBy(Auth::id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->visible(fn (): bool => Auth::check())
            ->authorize(fn (): bool => Auth::check())
            ->action(function (array $data): void {
                abort_unless(Auth::check(), 403);

                // Re-checked at the point of deletion, not just when the options were built:
                // the form's option list is a UI convenience, and the ownership clause here is
                // the actual gate.
                $deleted = TableView::query()
                    ->whereKey($data['view_id'])
                    ->where('resource', $this->savedViewResourceKey())
                    ->ownedBy(Auth::id())
                    ->delete();

                abort_unless($deleted > 0, 403);

                Notification::make()
                    ->title(__('admin.saved_views.deleted'))
                    ->success()
                    ->send();
            });
    }

    /** Save whatever the list is showing right now, under a name. */
    protected function saveTableViewAction(): Action
    {
        return Action::make('saveTableView')
            ->label(__('admin.saved_views.save'))
            ->icon('heroicon-o-bookmark-square')
            ->color('gray')
            ->modalHeading(__('admin.saved_views.save'))
            ->modalDescription(__('admin.saved_views.save_description'))
            ->schema([
                TextInput::make('name')
                    ->label(__('admin.fields.name'))
                    ->required()
                    ->maxLength(120),
                Toggle::make('is_shared')
                    ->label(__('admin.saved_views.share'))
                    ->helperText(__('admin.saved_views.share_help')),
            ])
            ->visible(fn (): bool => Auth::check())
            // The UI half is visible(); this is the gate. Both, per the codebase rule — hidden
            // implying disabled is an upstream detail, authorize() is a stated intent.
            ->authorize(fn (): bool => Auth::check())
            ->action(function (array $data): void {
                abort_unless(Auth::check(), 403);

                TableView::create([
                    'resource' => $this->savedViewResourceKey(),
                    'name' => $data['name'],
                    'state' => $this->currentTableState(),
                    'user_id' => Auth::id(),
                    'is_shared' => (bool) ($data['is_shared'] ?? false),
                ]);

                Notification::make()
                    ->title(__('admin.saved_views.saved'))
                    ->body(__('admin.saved_views.saved_body'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Which columns the operator is looking at, as `name => isToggled`.
     *
     * Only the TOGGLEABLE ones. A column that cannot be turned off records no choice — Filament
     * forces its `isToggled` to true when it re-syncs — so storing it would be storing noise that
     * looks like a decision, and would pin today's fixed columns into a row read a year from now.
     *
     * Column GROUPS are walked into rather than stored as containers: names are unique across a
     * table, so a flat map reapplies correctly whether or not the column still sits in a group.
     *
     * @return array<string, bool>
     */
    protected function savedViewColumnToggles(): array
    {
        $toggles = [];

        foreach ($this->tableColumns ?? [] as $item) {
            foreach ($item['columns'] ?? [$item] as $column) {
                if (($column['isToggleable'] ?? false) && isset($column['name'])) {
                    $toggles[$column['name']] = (bool) ($column['isToggled'] ?? true);
                }
            }
        }

        return $toggles;
    }

    /**
     * Apply the columns of the view named in the URL, if there is one.
     *
     * Livewire calls `booted{TraitName}` after `mount`, and Filament's own
     * `bootedInteractsWithTable()` calls `initTableColumnManager()`. **Either order converges**:
     * that method returns early when `$tableColumns` is already filled, and re-applying an
     * already-applied state is idempotent — so this does not depend on the order two traits happen
     * to be listed in.
     *
     * It runs on every Livewire request and does nothing on almost all of them: `tableView` is a
     * plain query parameter, present only on the page load the link produced. After that Filament's
     * own session persistence carries the layout, exactly as it does for a hand-toggled column — so
     * opening a view does not re-pin its columns every time the operator changes one.
     */
    public function bootedSavesTableViews(): void
    {
        $id = request()->query('tableView');

        if (! is_numeric($id) || ! Auth::check()) {
            return;
        }

        $view = TableView::query()
            ->whereKey((int) $id)
            ->where('resource', $this->savedViewResourceKey())
            ->visibleTo(Auth::id())
            ->first();

        // Silently, and deliberately not a 403. The id names a display preference, not a record:
        // a view someone deleted, or one belonging to a colleague who never shared it, should open
        // the list on its default columns rather than refuse the whole page.
        if ($view === null) {
            return;
        }

        $this->applySavedViewColumns($view->columnState());
    }

    /**
     * Rebuild Filament's column state from THIS user's table, adopting only the stored toggles.
     *
     * Built from `getDefaultTableColumnState()` outward rather than from the stored row inward,
     * which is what makes a shared view safe: a name the current table does not have is never
     * introduced, and a column this user may not toggle keeps whatever the table says.
     *
     * **Two layers, and only one of them is ours.** Filament's own
     * `syncTableColumnStateItemAttributes()` re-derives `label`, `isHidden` and `isToggleable` from
     * the current default state and forces `isToggled` back to true for a non-toggleable column —
     * measured, by deleting the guard in {@see savedViewColumn()} and watching the security test
     * stay green. So upstream is what currently enforces it and ours is the stated intent. Both are
     * kept for the reason the action-authorization seam gives: an upstream implementation detail
     * can change in a release and would silently remove the protection, so
     * `SavedTableViewsTest` pins Filament's half as a contract and turns the build red if an
     * upgrade changes it.
     *
     * An EMPTY map resets the list to its default columns instead of leaving whatever was in the
     * session. A view is a named state that a colleague must be able to open and see what you saw;
     * "whatever your browser happened to be showing" is not a state anyone named. Views saved
     * before this shipped state no columns and therefore open on the defaults.
     *
     * @param  array<string, bool>  $toggles
     */
    protected function applySavedViewColumns(array $toggles): void
    {
        $state = collect($this->getDefaultTableColumnState())
            ->map(function (array $item) use ($toggles): array {
                if (isset($item['columns'])) {
                    $item['columns'] = collect($item['columns'])
                        ->map(fn (array $column): array => $this->savedViewColumn($column, $toggles))
                        ->all();

                    return $item;
                }

                return $this->savedViewColumn($item, $toggles);
            })
            ->all();

        $this->applyTableColumnManager($state);
    }

    /**
     * One column, taking the saved toggle when the view stated one and this table allows it.
     *
     * @param  array<string, mixed>  $column
     * @param  array<string, bool>  $toggles
     * @return array<string, mixed>
     */
    protected function savedViewColumn(array $column, array $toggles): array
    {
        if (($column['isToggleable'] ?? false) && array_key_exists($column['name'] ?? '', $toggles)) {
            $column['isToggled'] = $toggles[$column['name']];
        }

        return $column;
    }

    /**
     * What the list is carrying right now, in query-string shape.
     *
     * Filters are stripped of their empty slots before storing. Filament's filter form fills
     * EVERY registered filter — a 12-filter list produces 12 entries of which one is set — and
     * storing all of them would mean a saved view pins today's full filter set, so a filter
     * removed from the resource later reappears in the URL as a key nothing binds.
     *
     * @return array<string, mixed>
     */
    protected function currentTableState(): array
    {
        return array_filter([
            'filters' => $this->meaningfulFilters(),
            'sort' => $this->tableSort,
            'search' => $this->tableSearch,
            // 'all' is the default tab; storing it would make the view fight a later default.
            'tab' => ($this->activeTab === null || $this->activeTab === 'all') ? null : $this->activeTab,
            // The columns are the other half of "what this list is showing", and until EG-32 a view
            // saved every part of that except the one an operator had to redo by hand each morning.
            'columns' => $this->savedViewColumnToggles(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * The filters the operator actually SET, dropping the empty slots Filament fills in.
     *
     * @return array<string, mixed>
     */
    protected function meaningfulFilters(): array
    {
        $set = [];

        foreach ($this->tableFilters ?? [] as $name => $state) {
            if (! is_array($state)) {
                continue;
            }

            // A filter is "set" when any of its own fields carries a value. `isActive => false`
            // is a checkbox left alone, and `value => null` is an untouched select.
            $values = array_filter(
                $state,
                fn ($value) => $value !== null && $value !== '' && $value !== false && $value !== [],
            );

            if ($values !== []) {
                $set[$name] = $values;
            }
        }

        return $set;
    }
}
