<?php

namespace App\Filament\Admin\Resources\UtilityMeters\RelationManagers;

use App\Models\Lease;
use App\Models\MeterReading;
use App\Models\Tenant;
use App\Models\UtilityMeter;
use App\Services\BillMeterReadingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.meter_readings');
    }

    /** Recharge gate — named once so visible() (UI) and action() (the real gate) can't drift. */
    public static function canBill(MeterReading $record): bool
    {
        $meter = $record->meter;

        return ! $record->isBilled()
            // A common-area / landlord meter (no unit) has no tenant to recharge — billing can
            // NEVER succeed, so hide the button rather than offer a click that only toasts a failure.
            // (A missing LEASE is dynamic — that stays a runtime toast, not a hidden button.)
            && $meter instanceof UtilityMeter && $meter->unit_id !== null
            && (float) $record->cost > 0
            && (auth()->user()?->can('invoices.create') ?? false);
    }

    /**
     * consumption × the meter's rate ON THE READING'S DATE → cost.
     *
     * The date is the point. A tariff is a dated ladder, so a reading keyed a week late — or
     * back-filled after a decreed rise — must be priced at what the supply cost when it was
     * consumed, not at what it costs today. `resolvedRatePerUnit()` is the one place that answers
     * that; reading `rate_per_unit` here directly would price a tariffed meter at 0.
     *
     * A meter with no rate at all leaves the operator's figure alone (rate 0 → no derivation), which
     * is the existing behaviour for a monitored-but-not-recharged meter.
     */
    protected function deriveCost(mixed $consumption, callable $set, mixed $readingDate = null): void
    {
        $meter = $this->ownerRecord;
        if (! $meter instanceof UtilityMeter || ! is_numeric($consumption)) {
            return;
        }

        $rate = $meter->resolvedRatePerUnit($readingDate ?: null);
        if ($rate > 0) {
            $set('cost', round((float) $consumption * $rate, 2));
        }
    }

    public function form(Schema $schema): Schema
    {
        // A billed reading is the evidence for a live recharge invoice, and editing it would NOT
        // touch that already-issued invoice — the reading and its invoice would silently diverge.
        // Lock the quantitative fields once billed (notes stays editable); cancel the invoice to edit.
        $lockedIfBilled = fn (?MeterReading $record): bool => $record?->isBilled() ?? false;

        return $schema->columns(2)->components([
            DatePicker::make('reading_date')
                ->label(__('admin.fields.reading_date'))
                ->required()
                ->native(false)
                ->default(now()->startOfMonth()->toDateString())
                // A reading is a snapshot of consumption that already happened — a future date would
                // mint a future-period recharge invoice (period derives from reading_date).
                ->maxDate(today())
                ->disabled($lockedIfBilled)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('utility_meter_id', $this->ownerRecord->id),
                )
                ->helperText(__('admin.helpers.reading_date')),
            TextInput::make('reading_value')
                ->label(__('admin.fields.reading_value'))
                ->numeric()
                ->required()
                ->minValue(0)
                ->step('0.01')
                ->disabled($lockedIfBilled)
                ->helperText(__('admin.helpers.reading_value'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.reading_value'))
                // Auto-fill consumption from delta vs prior reading when
                // empty. Operators can override before save if they have
                // a corrected figure (e.g. meter was reset mid-period).
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, $get, $set) {
                    if ($get('consumption')) {
                        return;
                    }
                    $meterId = $this->ownerRecord->id;
                    $candidateDate = $get('reading_date');
                    if (! $meterId || ! $candidateDate || ! is_numeric($state)) {
                        return;
                    }
                    $prior = MeterReading::query()
                        ->where('utility_meter_id', $meterId)
                        ->where('reading_date', '<', $candidateDate)
                        ->orderByDesc('reading_date')
                        ->first();
                    if (! $prior) {
                        return;
                    }
                    $delta = (float) $state - (float) $prior->reading_value;
                    if ($delta < 0) {
                        return; // meter rolled or reset — let operator key it
                    }
                    $set('consumption', round($delta, 2));
                    $this->deriveCost(round($delta, 2), $set, $candidateDate);
                }),
            TextInput::make('consumption')
                ->label(__('admin.fields.consumption'))
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->required()
                ->disabled($lockedIfBilled)
                ->suffix(fn () => $this->ownerRecord->unit_of_measurement ?: '')
                // Cost now DERIVES from the meter's tariff (consumption × rate) — it was a free
                // NOT-NULL field the operator computed mentally. Still editable for a corrected figure.
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $get, $set) => $this->deriveCost($state, $set, $get('reading_date'))),
            TextInput::make('cost')
                ->label(__('admin.fields.cost'))
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->prefix('EGP')
                ->disabled($lockedIfBilled)
                // Live feedback, which is what earns a helperText: the rate quoted here is the one
                // resolved for the reading's OWN date, so an operator back-filling a reading from
                // before a tariff rise can see that it is being priced at the old figure.
                ->helperText(function ($get) {
                    $meter = $this->ownerRecord instanceof UtilityMeter ? $this->ownerRecord : null;
                    $rate = $meter ? $meter->resolvedRatePerUnit($get('reading_date') ?: null) : 0.0;

                    return $rate > 0
                        ? __('admin.helpers.cost_derived', [
                            'rate' => rtrim(rtrim(number_format($rate, 4), '0'), '.'),
                            'uom' => $meter && $meter->unit_of_measurement ? $meter->unit_of_measurement : '',
                        ])
                        : __('admin.helpers.cost_no_rate');
                }),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: MeterReading carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->recordTitleAttribute('reading_date')
            ->columns([
                TextColumn::make('reading_date')
                    ->label(__('admin.fields.reading_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('reading_value')
                    ->label(__('admin.fields.reading_value'))
                    ->numeric(2)
                    ->alignRight(),
                TextColumn::make('consumption')
                    ->label(__('admin.fields.consumption'))
                    ->numeric(2)
                    ->alignRight()
                    ->weight('bold'),
                TextColumn::make('cost')
                    ->label(__('admin.fields.cost'))
                    ->money('EGP')
                    ->alignRight(),
                // Whether this reading has been recharged, and to which invoice — so an operator can
                // see at a glance what is still un-billed (the revenue that used to leak).
                TextColumn::make('billedInvoice.number')
                    ->label(__('admin.fields.recharge_invoice'))
                    ->badge()
                    ->color(fn (MeterReading $record) => $record->isBilled() ? 'success' : 'gray')
                    ->placeholder(__('admin.utility.not_billed')),
                TextColumn::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(40),
            ])
            ->filters([
                Filter::make('year')
                    ->label(__('admin.filters.year'))
                    ->schema([
                        // hiddenLabel, not a label: the Filter above is already called "Year",
                        // and a labelled input inside it reads "Year / Year".
                        TextInput::make('year')->hiddenLabel()->numeric()->placeholder((string) now()->year),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['year'] ?? null, fn (Builder $q, $y) => $q->whereYear('reading_date', $y))),
                // Surface the leak the recharge feature exists to close: billable readings that were
                // never invoiced. "Unbilled" = has a cost but no recharge invoice yet. (A reading whose
                // invoice was later CANCELLED still has billed_invoice_id set, so it reads as billed
                // here even though it can be re-billed — an acceptable simplification for a filter.)
                TernaryFilter::make('recharge_status')
                    ->label(__('admin.filters.recharge_status'))
                    ->placeholder(__('admin.filters.recharge_all'))
                    ->trueLabel(__('admin.filters.recharge_billed'))
                    ->falseLabel(__('admin.filters.recharge_unbilled'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('billed_invoice_id'),
                        false: fn (Builder $q) => $q->whereNull('billed_invoice_id')->where('cost', '>', 0),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_reading'))
                    ->modalHeading(__('admin.actions.add_reading'))
                    ->visible(fn () => auth()->user()?->can('utility_meters.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('utility_meters.edit') ?? false),
            ])
            ->recordActions([
                // Recharge this reading to the unit's tenant — the path that did not exist (readings
                // were recorded but the cost had to be re-keyed onto an invoice by hand).
                Action::make('billToTenant')
                    ->label(__('admin.actions.bill_reading'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    // Name WHO gets billed and for WHICH period, so an operator can catch a reading
                    // that resolves to the wrong (e.g. successor) tenant before confirming.
                    ->modalDescription(function (MeterReading $record) {
                        $lease = BillMeterReadingService::resolveLeaseFor($record);
                        if (! $lease instanceof Lease) {
                            return __('admin.actions.bill_reading_confirm');
                        }
                        $tenant = $lease->tenant;

                        return __('admin.actions.bill_reading_confirm_detailed', [
                            'tenant' => $tenant instanceof Tenant ? $tenant->name : '—',
                            'period' => $record->reading_date->isoFormat('MMM YYYY'),
                            'amount' => 'EGP '.number_format((float) $record->cost, 2),
                        ]);
                    })
                    ->visible(fn (MeterReading $record) => self::canBill($record))
                    ->action(function (MeterReading $record): void {
                        // action() is the real gate — mountAction() never checks visible().
                        abort_unless(self::canBill($record), 403);
                        try {
                            $invoice = app(BillMeterReadingService::class)->bill($record);
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.reading_billed'))
                            ->body(__('admin.notifications.reading_billed_body', [
                                'number' => $invoice->number,
                                'total' => 'EGP '.number_format((float) $invoice->total, 2),
                            ]))
                            ->send();
                    }),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('utility_meters.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('utility_meters.edit') ?? false),
                // No soft-deletes on readings, so this HARD-deletes. Hide it for a billed reading —
                // erasing it would orphan the live recharge invoice. The model's deleting-guard is the
                // real backstop (this visible() is UX only); cancel the invoice to free the reading.
                DeleteAction::make()
                    ->visible(fn (MeterReading $record) => (auth()->user()?->hasRole('super_admin') ?? false)
                        && ! $record->isBilled())
                    ->authorize(fn (MeterReading $record) => (auth()->user()?->hasRole('super_admin') ?? false)
                        && ! $record->isBilled()),
            ])
            ->defaultSort('reading_date', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateHeading(__('admin.empty.meter_readings.heading'))
            ->emptyStateDescription(__('admin.empty.meter_readings.description'));
    }
}
