<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\TableView;
use App\Support\Filament\SavedColumnLayout;
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

        $default = TableView::defaultFor($this->savedViewResourceKey(), Auth::id());

        $actions = $views->map(function (TableView $view) use ($default): Action {
            $isOwn = $view->user_id === Auth::id();
            $isDefault = $default?->getKey() === $view->getKey();

            return Action::make('savedView'.$view->id)
                // The one that opens says so. Without it the operator has no way to tell which of
                // five saved views the list came up on, and "why am I not seeing everything" is
                // then a question the screen cannot answer.
                ->label($isDefault ? $view->name.' · '.__('admin.saved_views.default_suffix') : $view->name)
                ->icon(match (true) {
                    $isDefault => 'heroicon-s-bookmark',
                    $isOwn => 'heroicon-o-bookmark',
                    default => 'heroicon-o-user-group',
                })
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
            // The way OUT of a default. A link to the plain list carries an EMPTY query string,
            // which is exactly what `mountSavesTableViews()` reads as "nothing asked for" and
            // redirects — so the obvious reset would bounce straight back to the default. The
            // marker makes the request say "the unfiltered list, deliberately".
            if ($default !== null) {
                $actions[] = Action::make('savedViewNone')
                    ->label(__('admin.saved_views.all_records'))
                    ->icon('heroicon-o-bars-3')
                    ->url(ResourceLink::index(static::getResource(), tableView: 'none'));
            }

            $actions[] = $this->chooseDefaultViewAction();
            $actions[] = $this->manageSavedViewsAction();
        }

        return ActionGroup::make($actions)
            ->label(__('admin.saved_views.menu'))
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => $views->isNotEmpty());
    }

    /**
     * Choose which saved view this list opens on — the second half of UX-11.
     *
     * Offered over the views this user may SEE, not only the ones they own: adopting the team's
     * shared arrears pack as your landing screen is the case the row is about, and refusing it
     * would be the half-capability this codebase keeps finding. Marking a shared view sets the
     * TEAM default, and a colleague's own personal default still wins over it
     * ({@see TableView::defaultFor()}), so this can never overrule a preference someone stated.
     *
     * Blank clears it. A `Select` with no `required()` is the whole of that — an operator who has
     * changed their mind about landing on a filtered list needs one obvious way back to none.
     */
    protected function chooseDefaultViewAction(): Action
    {
        return Action::make('chooseDefaultView')
            ->label(__('admin.saved_views.set_default'))
            ->icon('heroicon-o-star')
            ->modalHeading(__('admin.saved_views.set_default'))
            ->modalDescription(__('admin.saved_views.set_default_description'))
            ->modalSubmitActionLabel(__('admin.saved_views.set_default'))
            ->fillForm(fn (): array => [
                'view_id' => TableView::defaultFor($this->savedViewResourceKey(), Auth::id())?->getKey(),
            ])
            ->schema([
                Select::make('view_id')
                    ->label(__('admin.saved_views.menu'))
                    ->placeholder(__('admin.saved_views.no_default'))
                    ->options(fn (): array => $this->savedViewsForThisList()->pluck('name', 'id')->all()),
            ])
            ->visible(fn (): bool => Auth::check())
            ->authorize(fn (): bool => Auth::check())
            ->action(function (array $data): void {
                abort_unless(Auth::check(), 403);

                // Clearing is the blank case and must not require a view to exist.
                if (blank($data['view_id'] ?? null)) {
                    TableView::query()
                        ->where('resource', $this->savedViewResourceKey())
                        ->ownedBy(Auth::id())
                        ->update(['is_default' => false]);

                    Notification::make()->title(__('admin.saved_views.default_cleared'))->success()->send();

                    return;
                }

                // Re-resolved through `visibleTo`, not trusted from the payload: the option list is
                // a UI convenience and this is the gate. A view someone unshared between the modal
                // opening and its submit must not become anybody's landing screen.
                $view = TableView::query()
                    ->whereKey($data['view_id'])
                    ->where('resource', $this->savedViewResourceKey())
                    ->visibleTo(Auth::id())
                    ->first();

                abort_unless($view !== null, 403);

                $view->makeDefault();

                Notification::make()
                    ->title(__('admin.saved_views.default_set', ['name' => $view->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Open this list on the operator's default view, when they have one and asked for nothing else.
     *
     * **A redirect, not a state mutation.** This trait's rule is that a view IS a URL and there is
     * no second code path setting Livewire state; honouring it here keeps the address bar honest —
     * the operator can see which view they landed on, and paste the link to a colleague.
     *
     * Livewire fires trait `mount` hooks exactly once, on the initial page build
     * (`SupportLifecycleHooks::mount()` → `callTraitHook('mount')`), so this cannot fire on a
     * filter change or any later Livewire round trip. It is also skipped the moment the request
     * asks for ANYTHING — a filter, a sort, a search, a tab, an explicit `tableView`, or the
     * `tableView=none` the menu's "All records" carries — so it can never loop: the URL it redirects
     * to always carries at least `tableView`.
     *
     * Variadic by design: Livewire spreads the component's own mount params into every trait hook,
     * and a List page that grows one would otherwise fatal here.
     */
    public function mountSavesTableViews(mixed ...$params): void
    {
        // A view is a URL, and a URL must be the whole answer — measured, because it was not.
        //
        // `App\Support\TableDefaults` persists filters, search and sort in the session for every
        // table in the panel. Filament restores those only when the corresponding Livewire property
        // is still empty after the query string has been bound, so a view that NAMES a filter wins.
        // A view that names none does not: the link arrives with no `filters`, the session refills
        // them, and the operator opens "All leases" and sees last week's filter set. Proven on
        // `ListInvoices` — `?tableView=none` came back still carrying `status = draft`.
        //
        // That is worst for the menu's "All records" escape, whose entire job is to get back to the
        // plain list, and which carries `?tableView=none` precisely because an empty query string
        // is indistinguishable from a bare page load. An escape hatch that does not escape is worse
        // than none: the operator presses it, the list does not change, and they conclude the
        // filter is coming from the data.
        //
        // So: naming a view — any view, including `none` — clears the remembered state first. The
        // view then applies whatever it does carry on top of a clean list. Done in `mount` rather
        // than `booted` because Livewire runs trait `mount` hooks before `booted` ones, and
        // `bootedInteractsWithTable()` is what reads the session.
        if (request()->has('tableView')) {
            $this->forgetRememberedTableState();
        }

        if (request()->query() !== [] || ! Auth::check()) {
            return;
        }

        $view = TableView::defaultFor($this->savedViewResourceKey(), Auth::id());

        if ($view === null) {
            return;
        }

        $this->redirect(ResourceLink::index(
            static::getResource(),
            filters: $view->queryParameters()['filters'] ?? [],
            sort: $view->queryParameters()['sort'] ?? null,
            search: $view->queryParameters()['search'] ?? null,
            tab: $view->queryParameters()['tab'] ?? null,
            tableView: $view->getKey(),
        ));
    }

    /**
     * Drop this list's remembered filters, search and sort so a saved view applies to a clean list.
     *
     * The keys are Filament's own — the filter one is namespaced by the Filament tenant, the other
     * two by the component class — and they are asked of the component rather than rebuilt here, so
     * an upstream change to the scheme cannot leave this forgetting a key nobody writes any more.
     *
     * The COLUMN layout is deliberately not cleared: `bootedSavesTableViews()` rebuilds it from the
     * view's own stored toggles a moment later, and a view that stored none is documented to open
     * on the list's defaults rather than on whatever the session held.
     */
    protected function forgetRememberedTableState(): void
    {
        session()->forget([
            $this->getTableFiltersSessionKey(),
            $this->getTableSearchSessionKey(),
            $this->getTableColumnSearchesSessionKey(),
            $this->getTableSortSessionKey(),
        ]);
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

        $this->applySavedViewColumns($view->columnState(), $view->columnOrder());
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
     * @param  array<int, string>  $order  empty means the list's own order
     */
    protected function applySavedViewColumns(array $toggles, array $order = []): void
    {
        $this->applyTableColumnManager(
            SavedColumnLayout::rebuild($this->getDefaultTableColumnState(), $toggles, $order),
            // `wasReordered: true` only when the view actually states an order — it persists the
            // session flag that sends Filament down `syncReorderableColumnsFromDefaultTableColumnState()`,
            // which keeps THIS order while still re-deriving every label and flag from the reader's
            // own table.
            wasReordered: $order !== [],
        );
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
            'columns' => SavedColumnLayout::capture($this->tableColumns ?? [])[SavedColumnLayout::TOGGLES],
            // …and, since columns became reorderable, the ORDER they were in. A separate key: which
            // columns show and what order they show in are different questions, and a view saved
            // before reordering existed answers only the first.
            'column_order' => SavedColumnLayout::capture($this->tableColumns ?? [])[SavedColumnLayout::ORDER],
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
