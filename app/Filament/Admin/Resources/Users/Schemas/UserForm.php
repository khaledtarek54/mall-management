<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\Asset;
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
                    Select::make('assignedAssets')
                        ->label(__('admin.users.assigned_properties'))
                        ->relationship(
                            'assignedAssets',
                            'name',
                            modifyQueryUsing: fn ($query) => $query
                                ->where('assets.code', '!=', Asset::ALL_PROPERTIES_CODE),
                        )
                        ->multiple()
                        ->preload()
                        ->searchable()
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
