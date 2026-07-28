<?php

namespace App\Filament\Admin\Resources\Roles\Tables;

use App\Filament\Admin\Resources\Roles\RoleResource;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
            // RBAC hygiene: which roles did someone hand-roll, and which are dead weight.
            // Both are questions an access-control review asks and neither was answerable.
            ->filters([
                Filter::make('custom')
                    ->label(__('admin.tables.role.custom_only'))
                    ->query(fn ($query) => $query->whereNotIn('name', array_keys(RolesPermissionsSeeder::ROLES))),
                Filter::make('unassigned')
                    ->label(__('admin.tables.role.unassigned_only'))
                    ->query(fn ($query) => $query->whereDoesntHave('users')),
            ])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.empty.roles.heading'))
            ->emptyStateDescription(__('admin.empty.roles.description'))
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
