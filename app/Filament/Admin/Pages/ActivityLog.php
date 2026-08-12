<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Support\ActivityLogChangeRenderer;
use App\Support\ActivityVocabulary;
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

class ActivityLog extends Page implements HasTable
{
    // Aliased, not overridden via parent::. `getTableRecords()` reaches this class through a
    // TRAIT, and `parent::` walks the class chain only — Filament\Pages\Page has no such method,
    // so a plain override calling parent:: fell through to Livewire's __call and every render
    // died with "method does not exist".
    use InteractsWithTable {
        getTableRecords as filamentTableRecords;
    }
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 1;

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
        ];
    }

    public function getTitle(): string
    {
        return __('admin.activity.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
    }

    public static function canAccess(): bool
    {
        // The activity feed spans every property and has no asset_id, so it
        // can't be cleanly scoped to one property. Limit it to the
        // full-portfolio roles that legitimately see all properties — a
        // property-restricted user (owner / department staff) would
        // otherwise read other properties' financial + tenant activity.
        return Modules::enabled('activity_log')
            && (Auth::user()?->hasAnyRole(['super_admin', 'manager', 'viewer']) ?? false);
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
}
