<?php

namespace App\Filament\Admin\Resources\Roles\Tables;

use App\Filament\Admin\Resources\Roles\RoleResource;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.role_name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('admin.fields.role_description'))
                    ->state(fn ($record) => RolesPermissionsSeeder::ROLES[$record->name] ?? __('admin.fields.role_custom'))
                    ->color(fn ($record) => isset(RolesPermissionsSeeder::ROLES[$record->name]) ? null : 'info')
                    ->limit(80),
                TextColumn::make('permissions_count')
                    ->label(__('admin.tables.role.permissions'))
                    ->counts('permissions')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('users_count')
                    ->label(__('admin.tables.role.users'))
                    ->counts('users')
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([
                // Navigate to the Edit PAGE (not a modal): the per-module permission
                // CheckboxLists are dehydrated(false) and only EditRole::afterSave
                // gathers + syncs them (and audits the diff). A modal EditAction
                // would no-op the permission change AND skip the audit.
                EditAction::make()
                    ->visible(fn ($record) => RoleResource::canEdit($record))
                    ->url(fn ($record): string => RoleResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => RoleResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name');
    }
}
