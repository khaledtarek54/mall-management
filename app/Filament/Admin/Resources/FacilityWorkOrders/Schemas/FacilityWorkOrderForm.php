<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Schemas;

use App\Models\Department;
use App\Models\FacilityWorkOrder;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\EquipmentPicker;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FacilityWorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        // A done/cancelled order is terminal — read-only.
        $locked = fn (?FacilityWorkOrder $record) => $record !== null && $record->isTerminal();

        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.facility.fields.property'))
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn (?FacilityWorkOrder $record) => TenantScope::currentAssetId() !== null || $record !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),
            Select::make('unit_id')
                ->label(__('admin.facility.fields.unit'))
                // Clamped: asset_id is ->live() and client-supplied, so the raw value would
                // enumerate an invisible property's units.
                ->options(fn (Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                    ? Unit::query()->where('asset_id', $assetId)->orderBy('code')->pluck('code', 'id')->all()
                    : [])
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Select::make('equipment_id')
                ->live()
                ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, string $operation): void {
                    // Only on CREATE: re-picking the machine on an existing job must not silently
                    // re-grade a priority someone already decided.
                    if ($operation !== 'create' || ! $state) {
                        return;
                    }

                    $equipment = \App\Models\Equipment::find($state);

                    if ($equipment instanceof \App\Models\Equipment) {
                        $set('priority', $equipment->defaultWorkOrderPriority());
                    }
                })
                ->label(__('admin.facility.equipment.singular'))
                ->helperText(__('admin.facility.equipment_wo_hint'))
                // The machine this job is against (FR-PPM-03). Copied from the plan on a
                // generated order; chosen here for an ad-hoc one.
                //
                // The record's own stored machine is always included, even if since
                // deactivated or soft-deleted: Filament validates the CURRENT value against
                // the `in:` rule derived from these options, so filtering to ->active()
                // alone made an open work order uneditable — you couldn't even reschedule
                // it — the moment its machine was retired. Blanking the field escaped that,
                // but destroyed the FR-PPM-03 record of which machine the job was against.
                ->options(fn (Get $get, ?FacilityWorkOrder $record) => EquipmentPicker::options($get('asset_id'), $record?->equipment_id))
                ->searchable()
                ->preload()
                ->native(false)
                ->disabled($locked),
            TextInput::make('title')
                ->label(__('admin.facility.fields.title'))
                ->required()
                ->maxLength(255)
                ->disabled($locked),
            Select::make('category')
                ->label(__('admin.facility.fields.category'))
                ->options(fn () => __('admin.facility.categories'))
                ->default('other')
                ->required()
                ->native(false)
                ->disabled($locked),
            TextInput::make('job_value')
                ->label(__('admin.facility.penalty.job_value'))
                ->helperText(__('admin.facility.penalty.job_value_hint'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->visible(fn (?FacilityWorkOrder $record) => $record?->isCorrective() ?? false)
                ->disabled($locked),
            Select::make('priority')
                ->label(__('admin.facility.fields.priority'))
                ->options(fn () => __('admin.facility.priorities'))
                ->default('medium')
                ->required()
                ->native(false)
                // Criticality pre-fills this when a machine is picked, and the operator sees the
                // value before saving. Deliberately visible rather than applied on save: a system
                // that silently disagrees with an explicit choice teaches people to distrust it.
                ->helperText(__('admin.facility.helpers.priority_from_criticality'))
                ->disabled($locked),
            DatePicker::make('scheduled_for')
                ->label(__('admin.facility.fields.scheduled_for'))
                ->default(now())
                ->required()
                ->native(false)
                ->disabled($locked),
            Select::make('department_id')
                ->label(__('admin.facility.fields.department'))
                ->options(fn () => Department::selectableOptions())
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Select::make('vendor_id')
                ->label(__('admin.facility.fields.vendor'))
                // Only dispatchable vendors (active + COI not lapsed); the saving guard is the real gate.
                ->options(fn ($record) => Vendor::assignableOptions($record?->vendor_id))
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Textarea::make('notes')
                ->label(__('admin.facility.fields.notes'))
                ->rows(2)
                ->columnSpanFull()
                ->disabled($locked),
        ]);
    }
}
