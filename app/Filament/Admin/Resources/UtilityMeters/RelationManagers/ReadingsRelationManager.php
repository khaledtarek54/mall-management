<?php

namespace App\Filament\Admin\Resources\UtilityMeters\RelationManagers;

use App\Models\MeterReading;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.meter_readings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('reading_date')
                ->label(__('admin.fields.reading_date'))
                ->required()
                ->native(false)
                ->default(now()->startOfMonth()->toDateString())
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule) => $rule->where('utility_meter_id', $this->ownerRecord->id),
                )
                ->helperText(__('admin.helpers.reading_date')),
            TextInput::make('reading_value')
                ->label(__('admin.fields.reading_value'))
                ->numeric()
                ->required()
                ->minValue(0)
                ->step('0.01')
                ->helperText(__('admin.helpers.reading_value'))
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
                }),
            TextInput::make('consumption')
                ->label(__('admin.fields.consumption'))
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->required()
                ->suffix(fn () => $this->ownerRecord->unit_of_measurement ?: ''),
            TextInput::make('cost')
                ->label(__('admin.fields.cost'))
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->prefix('EGP'),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
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
                TextColumn::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(40),
            ])
            ->filters([
                Filter::make('year')
                    ->label(__('admin.filters.year'))
                    ->schema([
                        TextInput::make('year')->numeric()->placeholder((string) now()->year),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['year'] ?? null, fn (Builder $q, $y) => $q->whereYear('reading_date', $y))),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_reading'))
                    ->visible(fn () => auth()->user()?->can('utility_meters.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('utility_meters.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('reading_date', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateHeading(__('admin.empty.meter_readings.heading'))
            ->emptyStateDescription(__('admin.empty.meter_readings.description'));
    }
}
