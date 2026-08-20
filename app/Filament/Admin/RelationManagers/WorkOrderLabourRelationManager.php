<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\Trade;
use App\Models\User;
use App\Support\Filament\EntitySelect;
use App\Support\Modules;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * ساعات العمل — the hours that make in-house work cost something.
 *
 * **Ask for time, never for money.** The technician is asked how long it took and who did it; the
 * craft rate turns that into cost. Nobody types a figure, because a typed cost is a guess with a
 * decimal point — Maximo §5, and the reason `docs/benchmarks/fm/03-scenarios.md` S2 was
 * unanswerable: 180 in-house jobs a year cost EGP 0 on every report.
 *
 * The rate is frozen on the row at entry, so a rise in the trade's standard rate never re-prices
 * work done last March.
 */
class WorkOrderLabourRelationManager extends RelationManager
{
    protected static string $relationship = 'labour';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.facility.labour.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('facility') && (auth()->user()?->can('facility.view') ?? false);
    }

    private function order(): FacilityWorkOrder
    {
        return $this->getOwnerRecord();
    }

    /** Booking time is completing work, not editing a document — the same right the checklist uses. */
    private function canBook(): bool
    {
        return auth()->user()?->can('facility.complete') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('worked_on')
                ->label(__('admin.facility.fields.worked_on'))
                ->required()
                ->default(now())
                // Hours cannot be booked before the job existed, and a future date is a typo.
                ->maxDate(now())
                ->native(false),

            TextInput::make('hours')
                ->label(__('admin.facility.fields.hours'))
                ->numeric()
                ->required()
                ->minValue(0.25)
                ->maxValue(24)
                ->step(0.25)
                ->helperText(__('admin.facility.help.hours')),

            // Defaults to the job's trade — the common case — but an electrician helping on an
            // HVAC job books their own craft, or both trades are misreported.
            Select::make('trade_id')
                ->label(__('admin.facility.fields.trade'))
                ->options(fn (?FacilityWorkOrderLabour $record) => Trade::options($record?->trade_id))
                ->default(fn () => $this->order()->trade_id)
                ->native(false)
                ->searchable()
                ->helperText(__('admin.facility.help.labour_trade')),

            EntitySelect::make('user_id')
                ->label(__('admin.facility.fields.worked_by'))
                ->entity(User::class)
                ->searchable()
                // Nullable on purpose: a supervisor books a crew of three as one row of hours, and
                // refusing that pushes people to book nothing at all.
                ->helperText(__('admin.facility.help.worked_by')),

            TextInput::make('notes')
                ->label(__('admin.fields.notes'))
                ->maxLength(255)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['trade', 'user']))
            ->columns([
                TextColumn::make('worked_on')
                    ->label(__('admin.facility.fields.worked_on'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('hours')
                    ->label(__('admin.facility.fields.hours'))
                    ->numeric(decimalPlaces: 2)
                    ->summarize(Sum::make()->label(__('admin.facility.fields.hours'))),

                TextColumn::make('trade')
                    ->label(__('admin.facility.fields.trade'))
                    ->badge()
                    ->state(fn (FacilityWorkOrderLabour $r): string => $r->trade?->label() ?? '—'),

                TextColumn::make('user.name')
                    ->label(__('admin.facility.fields.worked_by'))
                    ->placeholder('—'),

                TextColumn::make('hourly_rate')
                    ->label(__('admin.facility.fields.standard_hourly_rate'))
                    ->money('EGP')
                    // A blank rate is the operator's own gap, and it is worth seeing on the row
                    // rather than discovering as a missing total.
                    ->placeholder(__('admin.facility.no_rate'))
                    ->toggleable(),

                TextColumn::make('cost')
                    ->label(__('admin.facility.fields.cost'))
                    ->money('EGP')
                    ->placeholder(__('admin.facility.no_rate'))
                    ->summarize(Sum::make()->money('EGP')->label(__('admin.facility.fields.cost'))),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.facility.labour.book'))
                    ->visible(fn (): bool => $this->canBook())
                    ->authorize(fn (): bool => $this->canBook())
                    ->mutateDataUsing(function (array $data): array {
                        $data['recorded_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canBook())
                    ->authorize(fn (): bool => $this->canBook()),

                // A mis-keyed timesheet line is corrected by removing it — nothing posted, and the
                // work order simply recomputes. `#[DeletionAllowed]` records the same decision.
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canBook())
                    ->authorize(fn (): bool => $this->canBook()),
            ])
            ->defaultSort('worked_on', 'desc')
            ->emptyStateHeading(__('admin.facility.labour.empty'))
            ->emptyStateDescription(__('admin.facility.labour.empty_hint'));
    }
}
