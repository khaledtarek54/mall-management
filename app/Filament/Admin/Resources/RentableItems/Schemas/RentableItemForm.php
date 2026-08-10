<?php

namespace App\Filament\Admin\Resources\RentableItems\Schemas;

use App\Models\Area;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RentableItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.resources.rentable_item.singular'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.resources.asset.singular'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('code')
                        ->label(__('admin.fields.item_code'))
                        ->required()
                        ->maxLength(32)
                        ->helperText(__('admin.helpers.item_code'))
                        // Unique per property — P-001 exists in every mall.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, Get $get) => $rule
                                ->where('asset_id', TenantScope::clampAssetId($get('asset_id'))),
                        ),
                    Select::make('type')
                        ->label(__('admin.fields.item_type'))
                        ->options(fn () => __('admin.enums.rentable_item_type'))
                        ->default(RentableItem::TYPE_PARKING)
                        ->required()
                        ->native(false),
                    TextInput::make('name')
                        ->label(__('admin.fields.item_name'))
                        ->maxLength(255)
                        ->placeholder(__('admin.helpers.item_name_placeholder')),
                    // Both pickers are scoped to the item's own property. `clampAssetId` because
                    // asset_id is client-supplied: keyed raw, the options would disclose another
                    // mall's floors and zones.
                    Select::make('floor_id')
                        ->label(__('admin.pdf.floor'))
                        ->options(fn (Get $get) => Floor::query()
                            ->when(TenantScope::clampAssetId($get('asset_id')), fn ($q, $id) => $q->where('asset_id', $id))
                            ->orderBy('level')
                            ->get()
                            ->mapWithKeys(fn (Floor $f) => [$f->id => $f->label()])
                            ->all())
                        ->native(false)
                        ->searchable()
                        ->helperText(__('admin.helpers.floor_id')),
                    Select::make('area_id')
                        ->label(__('admin.fields.participant_area'))
                        ->options(fn (Get $get) => Area::query()
                            ->when(TenantScope::clampAssetId($get('asset_id')), fn ($q, $id) => $q->where('asset_id', $id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false)
                        ->searchable(),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.enums.rentable_item_status'))
                        ->default(RentableItem::STATUS_AVAILABLE)
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.item_status')),
                    TextInput::make('monthly_rate')
                        ->label(__('admin.fields.item_monthly_rate'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                        ->helperText(__('admin.helpers.item_monthly_rate')),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
