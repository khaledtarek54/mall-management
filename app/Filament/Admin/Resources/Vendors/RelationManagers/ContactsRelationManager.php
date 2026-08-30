<?php

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

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

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.vendor_contacts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('admin.fields.contact_person'))->required()->maxLength(200),
            TextInput::make('role')->label(__('admin.fields.role') ?: 'Role')->maxLength(100),
            TextInput::make('email')->label(__('admin.fields.email'))->email()->maxLength(200),
            TextInput::make('phone')->label(__('admin.fields.phone'))->tel()->maxLength(50),
            Toggle::make('is_primary')->label(__('admin.fields.primary_contact') ?: 'Primary contact')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: VendorContact carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.contact_person'))->weight('bold'),
                TextColumn::make('role')->label(__('admin.fields.role'))->placeholder('—')->color('gray'),
                TextColumn::make('phone')->label(__('admin.tables.vendor.phone'))->copyable()->placeholder('—'),
                TextColumn::make('email')->label(__('admin.fields.email'))->copyable()->placeholder('—'),
                IconColumn::make('is_primary')->boolean()->label(__('admin.fields.primary_contact')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.actions.add_contact'))
                    ->modalHeading(__('admin.actions.add_contact'))
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('is_primary', 'desc');
    }
}
