<?php

namespace App\Filament\Admin\Resources\UtilityTariffs\RelationManagers;

use App\Models\UtilityTariffRate;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The price ladder — every price this tariff has carried, and the day each came into force.
 *
 * **A price change is a new rung, not an edit.** That is the whole reason this is a dated list
 * rather than a number on the meter: a decree announced in November can be entered in November and
 * starts applying by itself in January, and a reading back-filled from December still prices at the
 * figure that was in force when the supply was consumed.
 *
 * There is no end date. A rung runs until the next one starts, which makes overlapping and missing
 * windows unrepresentable — the data error that makes a legacy charge schedule bill nothing.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.utility_tariffs.rate_ladder');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('rate_per_unit')
                ->label(__('admin.fields.rate_per_unit'))
                ->prefix('EGP')
                ->suffix(fn () => $this->getOwnerRecord()->unit_of_measurement
                    ? '/ '.$this->getOwnerRecord()->unit_of_measurement
                    : '')
                ->numeric()
                ->required()
                ->minValue(0)
                // Four decimals — published utility rates carry them (EGP 1.4500/kWh), and a field
                // that cannot express the decreed figure is worse than no configuration at all.
                ->step('0.0001'),

            DatePicker::make('effective_from')
                ->label(__('admin.fields.effective_from'))
                ->required()
                ->native(false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('utility_tariff_id', $this->getOwnerRecord()->getKey()))
                ->helperText(__('admin.helpers.utility_effective_from'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.utility_effective_from')),

            TextInput::make('note')
                ->label(__('admin.fields.note'))
                ->maxLength(255)
                ->columnSpanFull()
                ->helperText(__('admin.helpers.utility_rate_note')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('rate_per_unit')
            // A ladder is a handful of rows identified by a date and a price. There is nothing to
            // type that would find one, and a box that can never match reads as a broken feature.
            ->searchable(false)
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label(__('admin.fields.effective_from'))
                    ->date('d/m/Y')
                    ->sortable()
                    // Which rung is live, without making the operator compare dates against today.
                    ->description(fn (UtilityTariffRate $record) => $record->effective_from->isFuture()
                        ? __('admin.utility_tariffs.scheduled')
                        : null),

                TextColumn::make('rate_per_unit')
                    ->label(__('admin.fields.rate_per_unit'))
                    ->formatStateUsing(fn ($state) => 'EGP '.rtrim(rtrim(number_format((float) $state, 4), '0'), '.'))
                    ->badge(),

                TextColumn::make('note')
                    ->label(__('admin.fields.note'))
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.utility_tariffs.add_rate'))
                    ->authorize(fn () => auth()->user()?->can('utility_tariffs.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn () => auth()->user()?->can('utility_tariffs.edit') ?? false),
                // A rung prices nothing retroactively — `meter_readings.cost` is stored at entry —
                // so removing one changes what is priced NEXT and no history. Registered as
                // parent-managed in `DeletionPolicy`.
                DeleteAction::make()
                    ->authorize(fn () => auth()->user()?->can('utility_tariffs.edit') ?? false),
            ]);
    }
}
