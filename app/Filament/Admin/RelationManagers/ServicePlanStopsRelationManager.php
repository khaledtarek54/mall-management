<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\ServicePlan;
use App\Models\ServicePlanStop;
use App\Support\EquipmentPicker;
use App\Support\Modules;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * محطات الجولة — the machines one visit covers.
 *
 * **Maximo §6.** Adding stops turns a plan into a ROUTE: *"inspect all 42 fire extinguishers on
 * level 2"* becomes one work order with one line per device, and a failed line names the device
 * instead of being a sentence somebody has to read.
 *
 * A plan with no stops is an ordinary single-target plan and behaves exactly as before, so nothing
 * an operator already built changes.
 */
class ServicePlanStopsRelationManager extends RelationManager
{
    protected static string $relationship = 'stops';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.facility.route.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('facility') && (auth()->user()?->can('facility.view') ?? false);
    }

    private function plan(): ServicePlan
    {
        return $this->getOwnerRecord();
    }

    private function canEditRoute(): bool
    {
        return auth()->user()?->can('facility.edit') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            EquipmentPicker::make('equipment_id')
                ->label(__('admin.facility.equipment.singular'))
                ->required()
                // Scoped to the plan's own property: a round cannot walk into another mall.
                ->modifyOptionsQuery(fn ($query) => $query->where('asset_id', $this->plan()->asset_id))
                ->helperText(__('admin.facility.help.route_stop')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()
                ->default(fn () => ($this->plan()->stops()->max('sort_order') ?? 0) + 10)
                ->helperText(__('admin.facility.help.route_order')),

            TextInput::make('note')
                ->label(__('admin.fields.notes'))
                ->maxLength(160)
                ->helperText(__('admin.facility.help.route_note')),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            // A route is a handful of machines in a fixed order — read by scrolling, not searched.
            // `ServicePlanStop` carries no fold blob, so `TableDefaults` would render a box that
            // always returns nothing, which reads as "no such machine".
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('equipment'))
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('admin.fields.sort_order'))
                    ->sortable(),

                TextColumn::make('equipment')
                    ->label(__('admin.facility.equipment.singular'))
                    ->state(fn (ServicePlanStop $r): string => $r->equipment?->label() ?? '—')
                    ->weight('bold'),

                TextColumn::make('equipment.location')
                    ->label(__('admin.fields.location'))
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label(__('admin.fields.notes'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.facility.route.add_stop'))
                    ->modalHeading(__('admin.facility.route.add_stop'))
                    ->visible(fn (): bool => $this->canEditRoute())
                    ->authorize(fn (): bool => $this->canEditRoute()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->canEditRoute())->authorize(fn (): bool => $this->canEditRoute()),
                DeleteAction::make()->visible(fn (): bool => $this->canEditRoute())->authorize(fn (): bool => $this->canEditRoute()),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading(__('admin.facility.route.empty'))
            ->emptyStateDescription(__('admin.facility.route.empty_hint'));
    }
}
