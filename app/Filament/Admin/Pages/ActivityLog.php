<?php

namespace App\Filament\Admin\Pages;

use App\Support\ActivityLogChangeRenderer;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.activity-log';

    public static function getNavigationLabel(): string
    {
        return __('admin.activity.nav_label');
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
        return \App\Support\Modules::enabled('activity_log')
            && (\Illuminate\Support\Facades\Auth::user()?->hasAnyRole(['super_admin', 'manager', 'viewer']) ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->with('causer')->latest('id'))
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
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.activity.subjects.{$state}") : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'lease' => 'info',
                        'invoice' => 'warning',
                        'payment' => 'success',
                        'tenant' => 'primary',
                        'charge' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('subject_id')
                    ->label(__('admin.activity.record'))
                    ->formatStateUsing(function (Activity $record): string {
                        if (! $record->subject) {
                            return '—';
                        }
                        return match (class_basename($record->subject_type)) {
                            'Lease' => $record->subject->reference,
                            'Invoice' => $record->subject->number,
                            'Payment' => $record->subject->reference,
                            'Tenant' => $record->subject->name,
                            'Charge' => $record->subject->name,
                            default => '#' . $record->subject_id,
                        };
                    })
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('event')
                    ->label(__('admin.activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.activity.events.{$state}") : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
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
                            $indicators[] = __('admin.filters.created_from') . ': ' . \Carbon\Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = __('admin.filters.created_until') . ': ' . \Carbon\Carbon::parse($data['created_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
