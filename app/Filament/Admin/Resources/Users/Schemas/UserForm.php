<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\Asset;
use App\Support\Filament\EntitySelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.users.account'))
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->label(__('admin.users.name'))
                        ->required()
                        ->maxLength(120),
                    TextInput::make('email')
                        ->label(__('admin.fields.email'))
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(150),
                    TextInput::make('password')
                        ->label(__('admin.users.password'))
                        ->password()
                        ->revealable()
                        ->minLength(6)
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('admin.users.password_edit_helper') : null)
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.users.roles'))
                ->components([
                    Select::make('roles')
                        ->label(__('admin.users.role'))
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText(__('admin.users.role_helper'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.users.properties'))
                ->description(__('admin.users.properties_helper'))
                ->components([
                    // ACROSS properties, deliberately. This field is not "which property does this
                    // record belong to" — it is the field that GRANTS access to properties, and it
                    // defaults to every real one, so scoping it to the mall the grantor happens to be
                    // working in would make the form's own default fail its own validation.
                    //
                    // Dropping the "All Properties" pseudo-asset — which is all the old callback here
                    // did — is `OptionDisplay`'s job either way.
                    //
                    // Open question, unchanged by this and not silently decided here: `hr` holds
                    // `users.create`, so an HR user assigned to one mall can grant another mall's
                    // access from this screen. That is a permissions decision, not a search one.
                    EntitySelect::make('assignedAssets')
                        ->label(__('admin.users.assigned_properties'))
                        ->entity(Asset::class)
                        ->acrossProperties()
                        ->relationship('assignedAssets')
                        ->multiple()
                        // New users get every real property selected by default —
                        // it's easier to deselect than to remember to add them all.
                        // On edit, the existing pivot drives the value.
                        ->default(fn (string $operation): array => $operation === 'create'
                            ? Asset::where('code', '!=', Asset::ALL_PROPERTIES_CODE)->pluck('id')->all()
                            : [])
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
