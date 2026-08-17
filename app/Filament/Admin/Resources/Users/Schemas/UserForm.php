<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\Asset;
use App\Support\AssignedAssets;
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
                    // record belong to" — it is the field that GRANTS access to properties, so the
                    // OPTIONS span the portfolio. What stops a restricted grantor handing out access
                    // they do not hold is `UserResource::enforceGrantableAssetsRule()`, on save:
                    // a narrowed option list is not a gate, because the assignment arrives as a
                    // Livewire payload and a crafted request never opens the dropdown.
                    //
                    // Dropping the "All Properties" pseudo-asset — which is all the old callback here
                    // did — is `OptionDisplay`'s job either way.
                    EntitySelect::make('assignedAssets')
                        ->label(__('admin.users.assigned_properties'))
                        ->entity(Asset::class)
                        ->acrossProperties()
                        ->relationship('assignedAssets')
                        ->multiple()
                        // New users get every property THE GRANTOR HOLDS selected by default — it is
                        // easier to deselect than to remember to add them all. It used to default to
                        // every real property regardless of who was creating, which for a restricted
                        // grantor meant the form proposed precisely the assignment the save then
                        // blocks. `idsForCurrentUser()` returns null for super_admin, so an
                        // unconstrained grantor still gets the whole portfolio.
                        ->default(function (string $operation): array {
                            if ($operation !== 'create') {
                                return []; // on edit the existing pivot drives the value
                            }

                            $grantable = AssignedAssets::idsForCurrentUser();

                            return Asset::query()
                                ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
                                ->when($grantable !== null, fn ($query) => $query->whereIn('id', $grantable))
                                ->pluck('id')
                                ->all();
                        })
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
