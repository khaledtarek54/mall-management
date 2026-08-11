<?php

namespace App\Filament\Admin\Resources\Units\Schemas;

use App\Models\Area;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use App\Models\Unit;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.unit_details'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.tables.unit.asset'))
                        // Scope to the user's visible properties so an "All Properties"
                        // user cannot create a unit under a property outside their set
                        // (null = unrestricted: super_admin / portfolio roles).
                        ->relationship('asset', 'name', modifyQueryUsing: function ($query) {
                            $visibleAssetIds = \App\Support\TenantScope::visibleAssetIds();

                            return $visibleAssetIds !== null
                                ? $query->whereIn('id', $visibleAssetIds)
                                : $query;
                        })
                        ->required()
                        ->native(false)
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        // Drives the zone picker below (a unit may only sit in its own mall's
                        // zones); clear a now-cross-property zone if the property changes.
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('area_id', null)),
                    TextInput::make('code')
                        ->label(__('admin.tables.unit.code'))
                        ->required()
                        ->maxLength(20)
                        // Clamped: `asset_id` is client-supplied, and a unique rule keyed on
                        // the raw value leaks whether a unit code exists in a property the
                        // user cannot see (TenantScope::clampAssetId).
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', \App\Support\TenantScope::clampAssetId($get('asset_id'))))
                        ->placeholder('A-01'),
                    // SELECTED from the property's register, not typed. Free text left "G" and
                    // "Ground" as two different floors to anything that grouped, and an ordinal on
                    // every unit asked two hundred rows to repeat the same number. Set up on the
                    // property (Asset → Floors); chosen here.
                    Select::make('floor_id')
                        ->label(__('admin.pdf.floor'))
                        ->relationship(
                            'floor',
                            'code',
                            // Scoped to the unit's own property, and ordered bottom-up.
                            modifyQueryUsing: fn ($query, \Filament\Schemas\Components\Utilities\Get $get) => $query
                                ->when(
                                    \App\Support\TenantScope::clampAssetId($get('asset_id')),
                                    fn ($q, $id) => $q->where('asset_id', $id),
                                )
                                ->orderBy('level'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (\App\Models\Floor $floor) => $floor->label())
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->helperText(__('admin.helpers.floor_id')),
                    Select::make('category')
                        ->label(__('admin.tables.unit.category'))
                        ->options(fn () => __('admin.enums.category'))
                        ->required()
                        ->native(false),
                    Select::make('area_id')
                        ->label(__('admin.tables.unit.area_zone'))
                        ->helperText(__('admin.tables.unit.area_zone_hint'))
                        // Only this unit's OWN property's active zones — `asset_id` is
                        // client-supplied (enabled in All-Properties mode), so it's clamped
                        // through TenantScope::clampAssetId(); out of scope ⇒ no options. This is
                        // UX only — the server-side guarantee is UnitResource::assertAreaInScope on
                        // the create/edit pages (a crafted request can submit any id). The record's
                        // current zone stays selectable even if retired, so an edit doesn't drop it.
                        ->options(function (Get $get, ?Unit $record) {
                            $assetId = TenantScope::clampAssetId($get('asset_id'));
                            if ($assetId === null) {
                                return [];
                            }

                            return Area::query()
                                ->where('asset_id', $assetId)
                                ->where(function ($q) use ($record) {
                                    $q->active();
                                    if ($record?->area_id) {
                                        $q->orWhere('id', $record->area_id);
                                    }
                                })
                                ->orderBy('code')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->native(false)
                        ->placeholder(__('admin.tables.unit.no_area_zone')),
                    TextInput::make('area_sqm')
                        ->label(__('admin.tables.unit.area'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->suffix('m²')
                        // Editable only on CREATE, where it seeds the opening measurement. After
                        // that the area is a DATED record and changing it here would move the
                        // column without writing a row — CAM would keep apportioning on the old
                        // figure while every current-state screen showed the new one. Mirrors the
                        // rent fields on the lease form, which are read-only for the same reason
                        // and routed through their own action. The model refuses it either way.
                        ->disabled(fn (?\App\Models\Unit $record) => $record !== null)
                        ->dehydrated(fn (?\App\Models\Unit $record) => $record === null)
                        ->helperText(fn (?\App\Models\Unit $record) => $record === null
                            ? null
                            : __('admin.helpers.unit_area_locked')),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.unit'))
                        ->required()
                        ->default('vacant')
                        ->native(false),
                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
