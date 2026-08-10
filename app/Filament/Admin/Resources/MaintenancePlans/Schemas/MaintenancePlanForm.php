<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Schemas;

use App\Models\Department;
use App\Models\MaintenancePlan;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\EquipmentPicker;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use App\Support\FormTab;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class MaintenancePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        // A flat 16-field form, grouped into the four questions a plan actually answers (UX-13).
        // The fields were not in group order, so this reorders them rather than merely wrapping
        // ranges — `equipment_id` sat after the work description, and the checklist after the
        // assignment.
        //
        // SCOPE composes rather than excludes, which is how the generator already treats it: a
        // chiller (equipment) in the basement plant room (area) is both, and the service copies
        // every coordinate onto the work order so the job still says WHERE after the plan changes.
        return $schema->columns(1)->components([
            Tabs::make('maintenance_plan')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make(__('admin.preventive_maintenance.tabs.scope'), [
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
            // SOFT services (cleaning, landscaping, pest, waste, security) are LOCATION-scoped, not
            // equipment-scoped — "clean the food court", "sweep parking L2". Same clamp as unit_id.
            Select::make('area_id')
                ->label(__('admin.preventive_maintenance.fields.area'))
                ->helperText(__('admin.preventive_maintenance.area_hint'))
                ->options(fn (Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                    ? \App\Models\Area::query()->where('asset_id', $assetId)->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable()
                ->native(false),
            Select::make('equipment_id')
                ->label(__('admin.preventive_maintenance.equipment.singular'))
                ->helperText(__('admin.preventive_maintenance.equipment_hint'))
                // The machine this plan services (FR-PPM-01/03). Same clamp as unit_id.
                ->options(fn (Get $get, ?MaintenancePlan $record) => EquipmentPicker::options($get('asset_id'), $record?->equipment_id))
                // FR-PPM-01: Fixed maintenance is "per asset", so it must name the machine.
                // The model enforces this too — the form only makes it visible.
                ->required(fn (Get $get) => $get('maintenance_type') === MaintenancePlan::MAINTENANCE_TYPE_FIXED)
                ->searchable()
                ->preload()
                ->native(false),
                    ])->columns(2),

                    FormTab::make(__('admin.preventive_maintenance.tabs.work'), [
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
            TagsInput::make('checklist')
                ->label(__('admin.preventive_maintenance.fields.checklist'))
                ->placeholder('Add a check item…')
                ->columnSpanFull(),
            Textarea::make('description')
                ->label(__('admin.preventive_maintenance.fields.description'))
                ->rows(2)
                ->columnSpanFull(),
                    ])->columns(2),

                    FormTab::make(__('admin.preventive_maintenance.tabs.schedule'), [
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
            // Soft-service rounds usually land on set weekdays ("every Mon/Wed/Fri"). Leave all
            // unticked for "any day", which is how every existing plan behaves.
            \Filament\Forms\Components\CheckboxList::make('days_of_week')
                ->label(__('admin.preventive_maintenance.fields.days_of_week'))
                ->helperText(__('admin.preventive_maintenance.days_of_week_hint'))
                ->options(fn () => __('admin.preventive_maintenance.weekdays'))
                ->columns(4)
                ->columnSpanFull(),
            DatePicker::make('next_due_date')
                ->label(__('admin.preventive_maintenance.fields.next_due'))
                ->default(now())
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label(__('admin.preventive_maintenance.fields.active'))
                ->default(true),
                    ])->columns(2),

                    // Department AND vendor, both optional, deliberately NOT an XOR. The corrective
                    // path enforces one-or-the-other through `execution_type`, and that guard is
                    // scoped to CM on purpose: a preventive round genuinely splits — in-house does
                    // the monthly filter change, a contractor the annual statutory inspection. See
                    // module 26's doc for the asymmetry.
                    FormTab::make(__('admin.preventive_maintenance.tabs.assignment'), [
            Select::make('department_id')
                ->label(__('admin.preventive_maintenance.fields.department'))
                ->options(fn () => Department::selectableOptions())
                ->searchable()
                ->native(false),
            Select::make('vendor_id')
                ->label(__('admin.preventive_maintenance.fields.vendor'))
                // Only dispatchable vendors (active + COI not lapsed); the saving guard is the real gate.
                ->options(fn ($record) => Vendor::assignableOptions($record?->vendor_id))
                ->searchable()
                ->native(false),
                    ])->columns(2),
                ]),
        ]);
    }
}
