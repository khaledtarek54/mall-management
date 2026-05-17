<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

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
        ]);
    }
}
