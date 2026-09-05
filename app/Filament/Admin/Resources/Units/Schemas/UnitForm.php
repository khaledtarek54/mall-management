<?php

namespace App\Filament\Admin\Resources\Units\Schemas;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\Unit;
use App\Support\AreaFitsTheProperty;
use App\Support\Filament\CustomFieldsSchema;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\ProjectedState;
use App\Support\TenantScope;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.unit_details'))
                ->columns(3)
                ->components([
                    PropertyField::make()
                        ->label(__('admin.tables.unit.asset'))
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
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id'))))
                        ->placeholder('A-01'),
                    // SELECTED from the property's register, not typed. Free text left "G" and
                    // "Ground" as two different floors to anything that grouped, and an ordinal on
                    // every unit asked two hundred rows to repeat the same number. Set up on the
                    // property (Asset → Floors); chosen here.
                    EntitySelect::make('floor_id')
                        ->label(__('admin.pdf.floor'))
                        ->entity(Floor::class)
                        // Narrowed to the property chosen ABOVE — which is the form's own rule and
                        // not property isolation, so it stays here. Ordering is the registry's
                        // (bottom-up by level).
                        ->modifyOptionsQuery(fn ($query, Get $get) => $query->when(
                            TenantScope::clampAssetId($get('asset_id')),
                            fn ($q, $id) => $q->where('asset_id', $id),
                        ))
                        ->helperText(__('admin.helpers.floor_id')),
                    Select::make('category')
                        ->label(__('admin.tables.unit.category'))
                        ->options(fn () => __('admin.enums.category'))
                        ->required()
                        ->native(false),
                    EntitySelect::make('area_id')
                        ->label(__('admin.tables.unit.area_zone'))
                        ->helperText(__('admin.tables.unit.area_zone_hint'))
                        ->entity(Area::class)
                        // Only this unit's OWN property's active zones — `asset_id` is
                        // client-supplied (enabled in All-Properties mode), so it's clamped
                        // through TenantScope::clampAssetId(); out of scope ⇒ no options. This is
                        // UX only — the server-side guarantee is UnitResource::assertAreaInScope on
                        // the create/edit pages (a crafted request can submit any id). The record's
                        // current zone stays selectable even if retired, so an edit doesn't drop it.
                        ->modifyOptionsQuery(function ($query, Get $get, ?Unit $record) {
                            $assetId = TenantScope::clampAssetId($get('asset_id'));

                            return $assetId === null
                                ? $query->whereRaw('1 = 0')
                                : $query
                                    ->where('asset_id', $assetId)
                                    ->where(function ($q) use ($record) {
                                        $q->active();
                                        if ($record?->area_id) {
                                            $q->orWhere('id', $record->area_id);
                                        }
                                    });
                        })
                        ->placeholder(__('admin.tables.unit.no_area_zone')),
                    TextInput::make('area_sqm')
                        ->label(__('admin.tables.unit.area'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        // A shop cannot be bigger than the whole lettable part of the mall. The
                        // damage lands on the CAM apportionment rather than here, so nothing about
                        // the unit register would ever have shown it.
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $asset = Asset::find(TenantScope::clampAssetId($get('asset_id')));

                                if (AreaFitsTheProperty::exceeds($value === null ? null : (float) $value, $asset)) {
                                    $fail(AreaFitsTheProperty::message((float) $value, $asset));
                                }
                            },
                        ])
                        ->suffix('m²')
                        // Editable only on CREATE, where it seeds the opening measurement. After
                        // that the area is a DATED record and changing it here would move the
                        // column without writing a row — CAM would keep apportioning on the old
                        // figure while every current-state screen showed the new one. Mirrors the
                        // rent fields on the lease form, which are read-only for the same reason
                        // and routed through their own action. The model refuses it either way.
                        ->disabled(fn (?Unit $record) => $record !== null)
                        ->dehydrated(fn (?Unit $record) => $record === null)
                        ->helperText(fn (?Unit $record) => $record === null
                            ? null
                            : __('admin.helpers.unit_area_locked'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.unit_area_locked')),
                    // A UNIT'S OCCUPANCY IS DERIVED, SO ONLY TWO OF ITS FOUR STATES ARE YOURS TO
                    // STATE. `occupied` and `reserved` are what `Unit::recomputeStatus()` writes
                    // from the leases holding the unit, and `EditUnit::afterSave()` re-projects on
                    // every save — so offering them let an operator pick `Occupied`, be told
                    // "Saved", and find the row reading `Vacant` a second later, with nothing on
                    // screen to say the entry had been discarded. Reported by the tester exactly
                    // that way.
                    //
                    // On a unit the projector currently owns, the field is DISABLED rather than
                    // narrowed: it still has to render the true state, and a Select that cannot
                    // label its own stored value emits a `Rule::in` that refuses every save of the
                    // record on a field nobody touched. Disabled also means not dehydrated, so the
                    // column is left to the projector instead of being written back.
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn (?Unit $record) => collect(__('admin.statuses.unit'))
                            ->only(ProjectedState::isProjected(Unit::class, $record?->status)
                                ? [$record->status]
                                : ProjectedState::declarable(Unit::class))
                            ->all())
                        ->disabled(fn (?Unit $record) => ProjectedState::isProjected(Unit::class, $record?->status))
                        ->required()
                        ->default('vacant')
                        ->native(false)
                        ->helperText(fn (?Unit $record) => ProjectedState::isProjected(Unit::class, $record?->status)
                            ? __('admin.helpers.unit_status_projected')
                            : __('admin.helpers.unit_status_declarable')),
                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            // The operator's own fields for this record type (D-7). Renders nothing at all
            // until somebody defines one, so a fresh install is unchanged.
            ...CustomFieldsSchema::form('unit'),

        ]);
    }
}
