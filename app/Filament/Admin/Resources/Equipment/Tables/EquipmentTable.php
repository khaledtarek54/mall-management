<?php

namespace App\Filament\Admin\Resources\Equipment\Tables;

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit', 'parent'])->withCount('children'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.facility.fields.code'))
                    ->fontFamily('mono')
                    ->weight('bold')
                    // A sub-code is shown indented under its parent's code so the tree reads
                    // at a glance without a nested table.
                    ->formatStateUsing(fn (string $state, Equipment $record) => $record->parent_id ? '└─ '.$state : $state)
                    ->description(fn (Equipment $record) => $record->parent?->code)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_en')
                    ->label(__('admin.facility.fields.name'))
                    ->description(fn (Equipment $record) => $record->name_ar)
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.facility.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.facility.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.facility.categories.{$state}") : '—')
                    ->toggleable(),
                TextColumn::make('criticality')
                    ->label(__('admin.facility.fields.criticality'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.facility.criticalities.'.($state ?: 'routine')))
                    ->color(fn (?string $state) => match ($state) {
                        Equipment::CRITICAL => 'danger',
                        Equipment::IMPORTANT => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('unit.code')
                    ->label(__('admin.facility.fields.unit'))
                    ->placeholder(__('admin.facility.equipment.common_area'))
                    ->toggleable(),
                TextColumn::make('location')
                    ->label(__('admin.facility.fields.location'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('children_count')
                    ->label(__('admin.facility.fields.sub_codes'))
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('admin.facility.fields.active'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('criticality')
                    ->label(__('admin.facility.fields.criticality'))
                    ->options(fn () => collect(Equipment::CRITICALITIES)
                        ->mapWithKeys(fn (string $c) => [$c => __("admin.facility.criticalities.{$c}")])
                        ->all()),
                SelectFilter::make('category')
                    ->label(__('admin.facility.fields.category'))
                    ->options(fn () => collect(['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'elevator', 'fire-safety', 'generator', 'other'])
                        ->mapWithKeys(fn (string $c) => [$c => __("admin.facility.categories.{$c}")])
                        ->all()),
                TernaryFilter::make('is_active')
                    ->label(__('admin.facility.fields.active')),
                Filter::make('roots')
                    ->label(__('admin.facility.equipment.roots_only'))
                    ->query(fn ($query) => $query->roots()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => EquipmentResource::canView($record))
                    ->authorize(fn ($record) => EquipmentResource::canView($record)),
                EditAction::make()->visible(fn (Equipment $record) => EquipmentResource::canEdit($record)),
            ])
            ->defaultSort('code')
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->emptyStateHeading(__('admin.empty.equipment.heading'))
            ->emptyStateDescription(__('admin.empty.equipment.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.equipment.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
