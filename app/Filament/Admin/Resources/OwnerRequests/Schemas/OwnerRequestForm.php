<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Schemas;

use App\Models\OwnerRequest;
use App\Models\User;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\Filament\TenureRange;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
                    EntitySelect::make('assigned_to_user_id')
                        ->label(__('admin.tables.owner_request.assigned_owner'))
                        ->entity(User::class)
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
                            ->where('id', '!=', Auth::id()))
                        ->visible(fn ($get) => $get('recipient') === 'owner')
                        ->required(fn ($get) => $get('recipient') === 'owner'),
                    // Scoped to the user's own properties — an owner picks only what they own,
                    // super_admin sees all. Both halves (drop the ALL pseudo-asset, restrict to the
                    // visible set) are OptionDisplay's; `accessibleAssets()` and `visibleAssetIds()`
                    // resolve to the same set for an owner.
                    // FREE by design — see PropertyField::PORTFOLIO_LEVEL. A general owner
                    // question is about no single mall, and CreateOwnerRequest guards the
                    // property only when one was actually chosen.
                    PropertyField::scope(allMeans: '—')
                        ->label(__('admin.tables.owner_request.property')),
                    Select::make('priority')
                        ->label(__('admin.tables.owner_request.priority'))
                        ->options(fn () => collect(OwnerRequest::PRIORITIES)
                            ->mapWithKeys(fn ($p) => [$p => __("admin.owner_requests.priorities.{$p}")]))
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
                        ->minDate(TenureRange::endsOnOrAfter('scheduled_from')),
                ]),
        ]);
    }
}
