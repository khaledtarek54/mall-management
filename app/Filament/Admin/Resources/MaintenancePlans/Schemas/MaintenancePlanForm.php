<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Schemas;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\MaintenancePlan;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MaintenancePlanForm
{
    /**
     * Selectable machines for the chosen property — **plus the record's own stored one**,
     * even if since deactivated or soft-deleted.
     *
     * That last part is load-bearing, not politeness. Filament derives an `in:` rule from a
     * Select's options and validates the CURRENT STORED VALUE against it
     * (`Select::getInValidationRuleValues()` → blank label → `Rule::in([])`, which always
     * fails). So filtering to `->active()` alone meant that the moment ops decommissioned a
     * machine, its plan could never be saved again — not even to deactivate it. The one
     * obvious escape was blocked too, so the record deadlocked with no UI way out while the
     * nightly scan kept raising work orders for it.
     *
     * @return array<int,string>
     */
    private static function equipmentOptions(Get $get, ?int $currentId): array
    {
        $assetId = TenantScope::clampAssetId($get('asset_id'));

        if ($assetId === null) {
            return [];
        }

        $options = Equipment::query()
            ->where('asset_id', $assetId)
            ->active()
            ->orderBy('code')
            ->get();

        if ($currentId !== null && ! $options->contains('id', $currentId)) {
            // withTrashed: a soft-deleted machine is excluded by the default scope, and is
            // exactly one of the states that used to deadlock the record.
            $current = Equipment::withTrashed()->whereKey($currentId)->first();

            if ($current !== null) {
                $options->push($current);
            }
        }

        return $options->mapWithKeys(fn (Equipment $e) => [$e->id => $e->label()])->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.preventive_maintenance.fields.property'))
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),
            Select::make('unit_id')
                ->label(__('admin.preventive_maintenance.fields.unit'))
                // Units of the chosen property; blank = common / asset-wide. Clamped:
                // asset_id is ->live() and client-supplied, so keying the option query on
                // the raw value would enumerate an invisible property's units.
                ->options(fn (Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                    ? Unit::query()->where('asset_id', $assetId)->orderBy('code')->pluck('code', 'id')->all()
                    : [])
                ->searchable()
                ->native(false),
            TextInput::make('title')
                ->label(__('admin.preventive_maintenance.fields.title'))
                ->required()
                ->maxLength(255),
            Select::make('category')
                ->label(__('admin.preventive_maintenance.fields.category'))
                ->options(fn () => __('admin.preventive_maintenance.categories'))
                ->default('other')
                ->required()
                ->native(false),
            Select::make('maintenance_type')
                ->label(__('admin.preventive_maintenance.fields.maintenance_type'))
                ->helperText(__('admin.preventive_maintenance.maintenance_type_hint'))
                ->options(fn () => __('admin.preventive_maintenance.maintenance_types'))
                ->default(MaintenancePlan::MAINTENANCE_TYPE_ROUTINE)
                ->required()
                ->live()
                ->native(false),
            Select::make('equipment_id')
                ->label(__('admin.preventive_maintenance.equipment.singular'))
                ->helperText(__('admin.preventive_maintenance.equipment_hint'))
                // The machine this plan services (FR-PPM-01/03). Same clamp as unit_id.
                ->options(fn (Get $get, ?MaintenancePlan $record) => self::equipmentOptions($get, $record?->equipment_id))
                // FR-PPM-01: Fixed maintenance is "per asset", so it must name the machine.
                // The model enforces this too — the form only makes it visible.
                ->required(fn (Get $get) => $get('maintenance_type') === MaintenancePlan::MAINTENANCE_TYPE_FIXED)
                ->searchable()
                ->preload()
                ->native(false),
            TextInput::make('frequency_value')
                ->label(__('admin.preventive_maintenance.fields.frequency_value'))
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),
            Select::make('frequency_unit')
                ->label(__('admin.preventive_maintenance.fields.frequency_unit'))
                ->options(fn () => __('admin.preventive_maintenance.frequency_units'))
                ->default('months')
                ->required()
                ->native(false),
            DatePicker::make('next_due_date')
                ->label(__('admin.preventive_maintenance.fields.next_due'))
                ->default(now())
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label(__('admin.preventive_maintenance.fields.active'))
                ->default(true),
            Select::make('department_id')
                ->label(__('admin.preventive_maintenance.fields.department'))
                ->options(fn () => Department::selectableOptions())
                ->searchable()
                ->native(false),
            Select::make('vendor_id')
                ->label(__('admin.preventive_maintenance.fields.vendor'))
                ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->native(false),
            TagsInput::make('checklist')
                ->label(__('admin.preventive_maintenance.fields.checklist'))
                ->placeholder('Add a check item…')
                ->columnSpanFull(),
            Textarea::make('description')
                ->label(__('admin.preventive_maintenance.fields.description'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
