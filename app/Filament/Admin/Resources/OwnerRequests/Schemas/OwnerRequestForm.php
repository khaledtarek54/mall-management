<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Schemas;

use App\Models\Asset;
use App\Models\OwnerRequest;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class OwnerRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.resources.owner_request.singular'))
                ->columns(2)
                ->components([
                    Select::make('recipient')
                        ->label(__('admin.tables.owner_request.recipient'))
                        ->options([
                            'operator' => __('admin.tables.owner_request.to_operator'),
                            'owner' => __('admin.tables.owner_request.to_owner'),
                        ])
                        ->default('operator')
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('assigned_to_user_id')
                        ->label(__('admin.tables.owner_request.assigned_owner'))
                        ->options(fn () => User::query()
                            ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
                            ->where('id', '!=', Auth::id())
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->visible(fn ($get) => $get('recipient') === 'owner')
                        ->required(fn ($get) => $get('recipient') === 'owner'),
                    Select::make('asset_id')
                        ->label(__('admin.tables.owner_request.property'))
                        ->options(function () {
                            $user = Auth::user();
                            $query = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE);
                            // Scope to the user's own properties — an owner picks
                            // only what they own; super_admin sees all.
                            if ($user && ! $user->hasRole('super_admin')) {
                                $query->whereIn('id', $user->accessibleAssets()->pluck('id'));
                            }

                            return $query->orderBy('name')->pluck('name', 'id');
                        })
                        ->searchable()
                        ->placeholder('—'),
                    Select::make('priority')
                        ->label(__('admin.tables.owner_request.priority'))
                        ->options(fn () => collect(OwnerRequest::PRIORITIES)->mapWithKeys(fn ($p) => [$p => Str::headline($p)]))
                        ->default('medium')
                        ->required()
                        ->native(false),
                    TextInput::make('subject')
                        ->label(__('admin.tables.owner_request.subject'))
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),
                    Textarea::make('body')
                        ->label(__('admin.tables.owner_request.message'))
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                    DateTimePicker::make('scheduled_from')
                        ->label(__('admin.tables.owner_request.schedule_from'))
                        ->native(false)
                        ->seconds(false),
                    DateTimePicker::make('scheduled_to')
                        ->label(__('admin.tables.owner_request.schedule_to'))
                        ->native(false)
                        ->seconds(false)
                        ->afterOrEqual('scheduled_from'),
                ]),
        ]);
    }
}
