<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Models\Asset;
use App\Support\ActivityLogChangeRenderer;
use App\Support\ActivityVocabulary;
use App\Support\AssignedAssets;
use App\Support\Filament\CauserFilter;
use App\Support\Modules;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Page implements DeliverableReport, HasTable
{
    /** An audit trail is unbounded; an unscheduled export must not be. */
    private const CSV_ROW_CAP = 5000;

    use ExportsReport;

    // Aliased, not overridden via parent::. `getTableRecords()` reaches this class through a
    // TRAIT, and `parent::` walks the class chain only — Filament\Pages\Page has no such method,
    // so a plain override calling parent:: fell through to Livewire's __call and every render
    // died with "method does not exist".
    use InteractsWithTable {
        getTableRecords as filamentTableRecords;
    }
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected string $view = 'filament.pages.activity-log';

    public static function getNavigationLabel(): string
    {
        return __('admin.activity.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    public function getTitle(): string
    {
        return __('admin.activity.page_title');
    }

    public static function canAccess(): bool
    {
        // The activity feed spans every property and has no asset_id, so it cannot be cleanly scoped
        // to one property. A property-restricted user (owner, department staff, mall_admin) would
        // otherwise read other properties' financial and tenant activity — which is why the grant
        // itself stops at the full-portfolio roles.
        //
        // Gated on the PERMISSION rather than on a role list (2026-08-19). It used to name
        // `['super_admin', 'manager', 'viewer']` inline, and `activity_log.view` was checked
        // nowhere — so `mall_admin`, which inherits every manager permission, held the right and was
        // refused by the screen, with no way to tell policy from bug. Two truths about one question.
        // The seeder now withholds the key from `mall_admin` explicitly, so the permission and the
        // screen say the same thing and access is unchanged.
        //
        // AND THE SCOPE IS CHECKED HERE, not left to the grant (2026-08-26). The reasoning above
        // rests on "the grant itself stops at the full-portfolio roles" — and that premise was
        // false. `viewer` and `manager` hold the key and BOTH can be pinned to one mall through the
        // ordinary property-assignment field on the user form; `AssignedAssets::idsFor()` restricts
        // anyone with an assignment, whatever their role. Measured: a `viewer` assigned to one mall
        // opened this page and read a tenant rename that happened in ANOTHER one — exactly the leak
        // `MALL_ADMIN_WITHHELD` exists to prevent, reached by a different door.
        //
        // Asked of the SCOPE rather than of the role list, so it cannot be reopened by a future
        // grant: a feed that spans every mall is readable by whoever is entitled to every mall.
        return Modules::enabled('activity_log')
            && (Auth::user()?->can('activity_log.view') ?? false)
            && static::readerHoldsEveryProperty();
    }

    /**
     * Is this reader entitled to the WHOLE portfolio the feed spans?
     *
     * "Holds every mall", not "has no assignment". The obvious form —
     * `! AssignedAssets::isRestricted()` — is wrong in both directions and the control test caught
     * it: `idsFor()` returns null only for a super admin or an account that was never assigned, and
     * an unassigned account cannot enter a mall's URL at all (`canAccessTenant()` refuses, the panel
     * 404s), so that rule collapses to "super admin only" while an auditor legitimately assigned to
     * EVERY mall would have been refused a feed that shows them nothing they cannot already see.
     *
     * Degrades the right way as the portfolio grows: an auditor holding two malls of three loses it
     * the day the third is registered, which is the moment the feed starts spanning something they
     * are not entitled to.
     */
    protected static function readerHoldsEveryProperty(): bool
    {
        $held = AssignedAssets::idsForCurrentUser();

        // Unconstrained — a super admin. Nothing to compare against.
        if ($held === null) {
            return true;
        }

        return Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->whereNotIn('id', $held)
            ->doesntExist();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Resolve this page's foreign keys in one query per referenced table, before any cell
     * renders. The Changes column names the records a diff points at ("Lease L-0042" rather
     * than "Lease 328"), and those references are stored as bare ids in a JSON column — no
     * Eloquent relation exists to eager-load, so this is the batching seam. Without it each
     * cell would resolve its own ids and a 100-row page would N+1.
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = $this->filamentTableRecords();

        app(ActivityVocabulary::class)->preloadReferences($records);

        return $records;
    }

    public function table(Table $table): Table
    {
        return $table
            // `subject` is eager-loaded too: the Record column dereferences it on every row, and
            // as a morphTo that was one query per row on top of the page query.
            ->query(Activity::query()->with(['causer', 'subject'])->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('admin.activity.who'))
                    ->placeholder(__('admin.activity.system'))
                    ->weight('medium'),
                TextColumn::make('log_name')
                    ->label(__('admin.activity.what'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => app(ActivityVocabulary::class)->subject($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'lease' => 'info',
                        'invoice' => 'warning',
                        'payment' => 'success',
                        'tenant' => 'primary',
                        'charge' => 'gray',
                        'access_control' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('subject_id')
                    ->label(__('admin.activity.record'))
                    // Names the record through the same convention the Changes column uses for
                    // foreign keys (label()/displayName(), then reference/number/name/code/title),
                    // so every one of the 60-odd logged models is covered. This was a six-arm
                    // match over class_basename, which meant everything outside those six —
                    // journal entries, work orders, owner statements, vendors — read as "#123".
                    ->formatStateUsing(fn (Activity $record): string => app(ActivityVocabulary::class)
                        ->describeSubject($record->subject) ?? '#'.$record->subject_id)
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('event')
                    ->label(__('admin.activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => app(ActivityVocabulary::class)->event($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted', 'voided' => 'danger',
                        'reversed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('changes')
                    ->label(__('admin.activity.changes'))
                    ->state(fn (Activity $record): string => app(ActivityLogChangeRenderer::class)->render($record))
                    ->html()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('admin.activity.subject'))
                    ->options(fn () => __('admin.activity.subjects')),
                SelectFilter::make('event')
                    ->label(__('admin.activity.event'))
                    ->options(fn () => __('admin.activity.events')),

                // WHO acted. The same definition the per-record Activities tab has always used —
                // this page, the one an auditor is actually sent to, had no control for it at all,
                // and `reportCsv()` exports `getFilteredTableQuery()`, so mounting it here answers
                // the scheduled CSV in the same move. See CauserFilter.
                CauserFilter::make(),

                // Quick presets — common audit windows. Picking one
                // overrides the custom date range below.
                SelectFilter::make('period')
                    ->label(__('admin.activity.period'))
                    ->options([
                        'today' => __('admin.activity.periods.today'),
                        'yesterday' => __('admin.activity.periods.yesterday'),
                        'last_7_days' => __('admin.activity.periods.last_7_days'),
                        'last_30_days' => __('admin.activity.periods.last_30_days'),
                        'this_month' => __('admin.activity.periods.this_month'),
                        'last_month' => __('admin.activity.periods.last_month'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $now = CarbonImmutable::now();

                        [$from, $to] = match ($data['value'] ?? null) {
                            'today' => [$now->startOfDay(), $now->endOfDay()],
                            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
                            'last_7_days' => [$now->subDays(7)->startOfDay(), $now->endOfDay()],
                            'last_30_days' => [$now->subDays(30)->startOfDay(), $now->endOfDay()],
                            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
                            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
                            default => [null, null],
                        };

                        return $query
                            ->when($from, fn (Builder $q, $start) => $q->where('created_at', '>=', $start))
                            ->when($to, fn (Builder $q, $end) => $q->where('created_at', '<=', $end));
                    }),

                // Custom date range — used when the preset doesn't fit.
                Filter::make('created_range')
                    ->label(__('admin.activity.when'))
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('admin.filters.created_from'))
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label(__('admin.filters.created_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = __('admin.filters.created_from').': '.Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = __('admin.filters.created_until').': '.Carbon::parse($data['created_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * The audit trail as CSV — the one log that earns a scheduled delivery.
     *
     * `OccupancyMap` was the other candidate and is deliberately left out: it is a picture, and the
     * tabular answer it would export is already the rent roll and the expiration schedule. An audit
     * trail is different — "who changed what, and when" is a compliance question somebody is
     * periodically asked to evidence, and evidencing it should not depend on remembering to visit
     * a screen.
     *
     * It exports what the TABLE is currently showing, filters included, rather than the whole log:
     * a saved view is a saved QUESTION ("access-control events this month"), and answering a
     * different one on delivery would make the emailed file quietly not the report that was saved.
     *
     * @return array{filename:string, headers:array<int,string>, rows:array<int,array<int,string>>}
     */
    public function reportCsv(): array
    {
        $vocabulary = app(ActivityVocabulary::class);
        $changes = app(ActivityLogChangeRenderer::class);

        // The table's own query — so every filter the operator set is honoured. Capped, because an
        // audit trail is unbounded by nature and an unlimited export is how a scheduled job runs
        // the box out of memory at 03:00.
        $records = $this->getFilteredTableQuery()
            ->with(['causer', 'subject'])
            ->latest('id')
            ->limit(self::CSV_ROW_CAP)
            ->get();

        $vocabulary->preloadReferences($records);

        $rows = $records->map(fn (Activity $activity): array => [
            $activity->created_at?->format('Y-m-d H:i') ?? '',
            $activity->causer?->name ?? __('admin.activity.system'),
            $vocabulary->subject($activity->log_name),
            $vocabulary->describeSubject($activity->subject) ?? '#'.$activity->subject_id,
            $vocabulary->event($activity->event),
            // The Changes column renders HTML for the screen; a CSV cell must be text. Same
            // renderer either way, so the emailed file says exactly what the page said —
            // re-deriving the wording here is how the two come to disagree.
            trim(html_entity_decode(strip_tags(
                str_replace(['<br>', '<br/>', '<br />', '</div>', '</li>'], ' · ', $changes->render($activity))
            ))),
        ])->all();

        return [
            'filename' => 'activity-log-'.now()->format('Y-m-d'),
            'headers' => [
                __('admin.activity.when'),
                __('admin.activity.who'),
                __('admin.activity.what'),
                __('admin.activity.record'),
                __('admin.activity.event'),
                __('admin.activity.changes'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The audit trail exports on its OWN permission, not on `reports.view`.
     *
     * {@see ExportsReport::mayExport()} answers `static::canAccess()` since SW-177 — so this override
     * is now byte-identical and kept DELIBERATELY: it pins this page's export to its own gate in its
     * own file, so a later change to the trait's default cannot silently re-route the audit trail's
     * export. The page is gated on `activity_log.view`, which
     * the seeder withholds from `mall_admin` precisely because the feed spans every property and
     * cannot be scoped to one. Inheriting the default would have made the export a second door into
     * exactly the cross-property data the screen's own gate exists to withhold.
     *
     * `canAccess()` rather than the bare permission, so the module switch is honoured too — the rule
     * the trait states is that whoever may read it on screen may take it away, and nobody may read
     * this screen with `activity_log` turned off.
     */
    public static function mayExport(): bool
    {
        return static::canAccess();
    }
}
