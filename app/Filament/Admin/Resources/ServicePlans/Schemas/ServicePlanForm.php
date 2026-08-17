<?php

namespace App\Filament\Admin\Resources\ServicePlans\Schemas;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Department;
use App\Models\ServicePlan;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\EquipmentPicker;
use App\Support\Filament\EntitySelect;
use App\Support\FormTab;
use App\Support\TenantScope;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ServicePlanForm
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
            Tabs::make('service_plan')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make('admin.facility.tabs.scope', [
                        EntitySelect::make('asset_id')
                            ->label(__('admin.facility.fields.property'))
                            ->entity(Asset::class)
                            ->default(fn () => TenantScope::currentAssetId())
                            ->disabled(fn () => TenantScope::currentAssetId() !== null)
                            ->dehydrated()
                            ->required()
                            ->live()
                            ->native(false),
                        EntitySelect::make('unit_id')
                            ->label(__('admin.facility.fields.unit'))
                            ->entity(Unit::class)
                            // Units of the chosen property; blank = common / asset-wide. Clamped:
                            // asset_id is ->live() and client-supplied, so keying the option query on
                            // the raw value would enumerate an invisible property's units.
                            ->modifyOptionsQuery(fn ($query, Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                                ? $query->where('asset_id', $assetId)
                                : $query->whereRaw('1 = 0')),
                        // SOFT services (cleaning, landscaping, pest, waste, security) are LOCATION-scoped, not
                        // equipment-scoped — "clean the food court", "sweep parking L2". Same clamp as unit_id.
                        EntitySelect::make('area_id')
                            ->label(__('admin.facility.fields.area'))
                            ->helperText(__('admin.facility.area_hint'))
                            ->entity(Area::class)
                            ->modifyOptionsQuery(fn ($query, Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                                ? $query->where('asset_id', $assetId)
                                : $query->whereRaw('1 = 0')),
                        Select::make('equipment_id')
                            ->label(__('admin.facility.equipment.singular'))
                            ->helperText(__('admin.facility.equipment_hint'))
                            // The machine this plan services (FR-PPM-01/03). Same clamp as unit_id.
                            ->options(fn (Get $get, ?ServicePlan $record) => EquipmentPicker::options($get('asset_id'), $record?->equipment_id))
                            // FR-PPM-01: Fixed maintenance is "per asset", so it must name the machine.
                            // The model enforces this too — the form only makes it visible.
                            ->required(fn (Get $get) => $get('plan_type') === ServicePlan::MAINTENANCE_TYPE_FIXED)
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])->columns(2),

                    FormTab::make('admin.facility.tabs.work', [
                        TextInput::make('title')
                            ->label(__('admin.facility.fields.title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label(__('admin.facility.fields.category'))
                            ->options(fn () => __('admin.facility.categories'))
                            ->default('other')
                            ->required()
                            ->native(false),
                        Select::make('plan_type')
                            ->label(__('admin.facility.fields.plan_type'))
                            ->helperText(__('admin.facility.plan_type_hint'))
                            ->options(fn () => __('admin.facility.plan_types'))
                            ->default(ServicePlan::MAINTENANCE_TYPE_ROUTINE)
                            ->required()
                            ->live()
                            ->native(false),
                        TagsInput::make('checklist')
                            ->label(__('admin.facility.fields.checklist'))
                            ->placeholder(__('admin.placeholders.checklist_item'))
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label(__('admin.facility.fields.description'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                    FormTab::make('admin.facility.tabs.schedule', [
                        TextInput::make('frequency_value')
                            ->label(__('admin.facility.fields.frequency_value'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Select::make('frequency_unit')
                            ->label(__('admin.facility.fields.frequency_unit'))
                            ->options(fn () => __('admin.facility.frequency_units'))
                            ->default('months')
                            ->required()
                            ->native(false),
                        // Soft-service rounds usually land on set weekdays ("every Mon/Wed/Fri"). Leave all
                        // unticked for "any day", which is how every existing plan behaves.
                        CheckboxList::make('days_of_week')
                            ->label(__('admin.facility.fields.days_of_week'))
                            ->helperText(__('admin.facility.days_of_week_hint'))
                            ->options(fn () => __('admin.facility.weekdays'))
                            ->columns(4)
                            ->columnSpanFull(),
                        DatePicker::make('next_due_date')
                            ->label(__('admin.facility.fields.next_due'))
                            ->default(now())
                            ->required()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label(__('admin.facility.fields.active'))
                            ->default(true),
                    ])->columns(2),

                    // Department AND vendor, both optional, deliberately NOT an XOR. The corrective
                    // path enforces one-or-the-other through `execution_type`, and that guard is
                    // scoped to CM on purpose: a preventive round genuinely splits — in-house does
                    // the monthly filter change, a contractor the annual statutory inspection. See
                    // module 26's doc for the asymmetry.
                    FormTab::make('admin.facility.tabs.assignment', [
                        EntitySelect::make('department_id')
                            ->label(__('admin.facility.fields.department'))
                            ->entity(Department::class)
                            ->searchable()
                            ->native(false),
                        Select::make('vendor_id')
                            ->label(__('admin.facility.fields.vendor'))
                            // Only dispatchable vendors (active + COI not lapsed); the saving guard is the real gate.
                            ->options(fn ($record) => Vendor::assignableOptions($record?->vendor_id))
                            ->searchable()
                            ->native(false),
                    ])->columns(2),
                ]),
        ]);
    }
}
