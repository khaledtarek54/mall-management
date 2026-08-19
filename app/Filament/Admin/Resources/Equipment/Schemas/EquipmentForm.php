<?php

namespace App\Filament\Admin\Resources\Equipment\Schemas;

use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\Unit;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class EquipmentForm
{
    /**
     * The selected property, clamped to what the user may actually see.
     *
     * Every query below is keyed on `asset_id`, which is **client-supplied** (it's
     * `->live()`, and the Select is enabled in All-Properties mode). See
     * `TenantScope::clampAssetId()` for why the raw value is unsafe here.
     */
    private static function inScopeAssetId(Get $get): ?int
    {
        return TenantScope::clampAssetId($get('asset_id'));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.facility.fields.property'))
                ->live(),

            EntitySelect::make('parent_id')
                ->label(__('admin.facility.fields.parent'))
                ->helperText(__('admin.facility.equipment.parent_hint'))
                ->entity(Equipment::class)
                // Same property only, and never itself or one of its own sub-codes —
                // otherwise the branch detaches from every root. The model re-checks both
                // on save: this Select is a convenience, not the enforcement point.
                ->modifyOptionsQuery(function ($query, Get $get, ?Equipment $record) {
                    $assetId = self::inScopeAssetId($get);

                    return $assetId === null
                        ? $query->whereRaw('1 = 0')
                        : $query
                            ->where('asset_id', $assetId)
                            ->when($record?->exists, fn ($q) => $q->whereNotIn('id', $record->selfAndDescendantIds()));
                }),

            TextInput::make('code')
                ->label(__('admin.facility.fields.code'))
                ->helperText(__('admin.facility.equipment.code_hint'))
                ->required()
                ->maxLength(40)
                // Unique per property, matching the DB's equipment_asset_code_unique.
                //
                // Clamped through inScopeAssetId() for the same reason as the pickers, and
                // it is subtler here: Laravel runs every field rule in one pass BEFORE any
                // mutate hook, and Rule::unique compiles to a raw query untouched by
                // Filament's tenancy scope — so assertAssetInScope() fires too late. Keyed
                // on the raw value, this rule answers "is this code taken in <property>?"
                // for a property the user cannot see: the write is refused either way, but
                // the presence of a `code` error vs only an `asset_id` error is a one-bit
                // existence oracle. null (out of scope) collapses it.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', self::inScopeAssetId($get))),

            // Was a HARDCODED subset of ten, duplicated in the table with a different list —
            // landscaping, pest control, waste and security were simply missing from both. One
            // register, one list, and an operator can add to it without a deploy.
            Select::make('trade_id')
                ->label(__('admin.facility.fields.trade'))
                ->options(fn (?Equipment $record) => Trade::options($record?->trade_id))
                ->native(false)
                ->searchable()
                ->helperText(__('admin.facility.help.equipment_trade')),

            Select::make('criticality')
                ->label(__('admin.facility.fields.criticality'))
                ->options(fn () => collect(Equipment::CRITICALITIES)
                    ->mapWithKeys(fn (string $c) => [$c => __("admin.facility.criticalities.{$c}")])
                    ->all())
                ->default(Equipment::ROUTINE)
                ->required()
                ->native(false)
                // States the effect, because a field whose consequence is invisible is a field that
                // stays on its default for ever.
                ->helperText(__('admin.facility.helpers.criticality')),

            TextInput::make('name_en')
                ->label(__('admin.facility.fields.name_en'))
                ->required()
                ->maxLength(255),

            TextInput::make('name_ar')
                ->label(__('admin.facility.fields.name_ar'))
                ->required()
                ->maxLength(255),

            EntitySelect::make('unit_id')
                ->label(__('admin.facility.fields.unit'))
                ->helperText(__('admin.facility.equipment.unit_hint'))
                ->entity(Unit::class)
                // Units of the property chosen above only. `whereRaw('1 = 0')` rather than an early
                // `[]`: the callback narrows a query now, and returning nothing from it would leave
                // the picker showing every unit in the portfolio.
                ->modifyOptionsQuery(function ($query, Get $get) {
                    $assetId = self::inScopeAssetId($get);

                    return $assetId === null
                        ? $query->whereRaw('1 = 0')
                        : $query->where('asset_id', $assetId);
                }),

            TextInput::make('location')
                ->label(__('admin.facility.fields.location'))
                ->maxLength(255),

            EntitySelect::make('fixed_asset_id')
                ->label(__('admin.facility.fields.fixed_asset'))
                ->helperText(__('admin.facility.equipment.fixed_asset_hint'))
                ->entity(FixedAsset::class)
                // The accounting twin, if this machine is also capitalised. Same property
                // only — a cross-property link would tie a mall's machine to another mall's
                // depreciation record.
                ->modifyOptionsQuery(function ($query, Get $get) {
                    $assetId = self::inScopeAssetId($get);

                    return $assetId === null
                        ? $query->whereRaw('1 = 0')
                        : $query->where('asset_id', $assetId);
                }),

            EntitySelect::make('inventoryItems')
                ->label(__('admin.facility.fields.spare_parts'))
                ->helperText(__('admin.facility.equipment.spare_parts_hint'))
                // FR-PPM-05. The item catalog is deliberately SHARED/unscoped ("a pump seal
                // is the same item everywhere"), so this select is intentionally not
                // property-filtered — and `InventoryItem` is `#[PortfolioShared]`, so
                // OptionDisplay does not narrow it either.
                ->entity(InventoryItem::class)
                ->relationship('inventoryItems')
                ->multiple()
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label(__('admin.facility.fields.active'))
                ->default(true),

            Textarea::make('notes')
                ->label(__('admin.facility.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
