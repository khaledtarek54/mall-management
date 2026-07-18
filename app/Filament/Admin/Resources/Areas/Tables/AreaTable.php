<?php

namespace App\Filament\Admin\Resources\Areas\Tables;

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Models\Area;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AreaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'supervisors'])->withCount('supervisors'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.areas.fields.code'))
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.areas.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.areas.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('supervisors.name')
                    ->label(__('admin.areas.fields.supervisors'))
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('supervisors_count')
                    ->label(__('admin.areas.fields.supervisor_count'))
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('admin.areas.fields.active'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.areas.fields.active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Area $record) => AreaResource::canEdit($record)),
            ])
            ->defaultSort('code');
    }
}
