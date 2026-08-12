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
                // A link, not a state mutation — see the class docblock.
                ->url(ResourceLink::index(
                    static::getResource(),
                    filters: $view->queryParameters()['filters'] ?? [],
                    sort: $view->queryParameters()['sort'] ?? null,
                    search: $view->queryParameters()['search'] ?? null,
                    tab: $view->queryParameters()['tab'] ?? null,
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
