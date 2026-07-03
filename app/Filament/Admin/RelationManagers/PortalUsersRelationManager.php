<?php

namespace App\Filament\Admin\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Manage a tenant's portal login accounts (req #9). Only admin tenant users may
 * submit/write in the portal; the rest are read-only. Created here, under the
 * specific tenant, so accounts are always scoped to their company.
 */
class PortalUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.portal_user.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.fields.name'))
                ->required()
                ->maxLength(150),
            TextInput::make('email')
                ->label(__('admin.fields.email'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->label(__('admin.fields.password'))
                ->password()
                ->revealable()
                ->minLength(8)
                // The TenantUser 'hashed' cast hashes on save; leave blank on
                // edit to keep the current password.
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText(__('admin.helpers.password_leave_blank'))
                ->maxLength(255),
            Toggle::make('is_admin')
                ->label(__('admin.fields.portal_admin'))
                ->helperText(__('admin.helpers.portal_admin'))
                // Portal admins can write in the portal (submit/pay), so granting
                // that flag is a super_admin-only act — mirrors the Delete gate below.
                // A non-super_admin editing a portal user keeps the current value.
                ->visible(fn () => Auth::user()?->hasRole('super_admin') ?? false)
                ->dehydrated(fn () => Auth::user()?->hasRole('super_admin') ?? false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_admin')
                    ->label(__('admin.fields.portal_admin'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                // Delete stays super_admin-only (project-wide convention).
                DeleteAction::make()
                    ->visible(fn () => Auth::user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('is_admin', 'desc');
    }
}
