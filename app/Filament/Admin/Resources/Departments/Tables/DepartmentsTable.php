<?php

namespace App\Filament\Admin\Resources\Departments\Tables;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.department.name'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label(__('admin.tables.department.code'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('head.name')
                    ->label(__('admin.tables.department.head'))
                    ->placeholder('—'),
                TextColumn::make('asset.name')
                    ->label(__('admin.tables.department.scope'))
                    ->placeholder(__('admin.tables.department.global')),
                IconColumn::make('is_active')
                    ->label(__('admin.tables.department.active'))
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label(__('admin.tables.department.sort_order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn ($record) => DepartmentResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => DepartmentResource::canDeleteAny()),
                ]),
            ]);
    }
}
