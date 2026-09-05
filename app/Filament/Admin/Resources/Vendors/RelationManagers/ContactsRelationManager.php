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
            TextInput::make('email')->label(__('admin.fields.email'))->email()->maxLength(255),
            TextInput::make('phone')->label(__('admin.fields.phone'))->tel()->maxLength(50),
            Toggle::make('is_primary')->label(__('admin.fields.primary_contact') ?: 'Primary contact')->columnSpanFull(),
            // THE ONLY WRITE PATH TO THE CONTRACTOR PORTAL, and until 2026-09-01 there was none.
            //
            // `VendorContact::canAccessPanel()` requires `is_portal_user`, the column defaults false
            // for every row, and nothing anywhere set it — no form field, no importer, no seeder, no
            // console command; a grep found the model, the migration and two readers and no writer
            // at all. Filament's own bootstrap door is shut for the same reason: its password-reset
            // page refuses to send a link to somebody who `! canAccessPanel()`. So the whole
            // `/vendor` panel — accept, evidence, update, quote, the dispatch bell, `VendorScope` —
            // was unenterable without editing MySQL by hand, which is the shape
            // `ServiceReachability` exists to catch one layer up: built, tested, and impossible to
            // start.
            //
            // Access, not identity: it grants nothing but the ability to sign in against their own
            // reset-token table. Turning it OFF is how access is withdrawn when somebody leaves the
            // contractor, which is why it is a field the operator can see and change rather than a
            // one-way invitation.
            Toggle::make('is_portal_user')
                ->label(__('admin.fields.vendor_portal_access'))
                ->helperText(__('admin.helpers.vendor_portal_access'))
                ->columnSpanFull(),
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
