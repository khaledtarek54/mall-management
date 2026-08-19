<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Schemas;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\Trade;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\EquipmentPicker;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class FacilityWorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        // A done/cancelled order is terminal — read-only.
        $locked = fn (?FacilityWorkOrder $record) => $record !== null && $record->isTerminal();

        return $schema->columns(2)->components([
            // Frozen once the job exists as well as pinned: the property decides the zone, the
            // equipment and the SLA clock, so re-homing a live work order would strand all three.
            PropertyField::make(alsoDisabledWhen: fn (?FacilityWorkOrder $record) => $record !== null)
                ->label(__('admin.facility.fields.property'))
                ->live(),
            EntitySelect::make('unit_id')
                ->label(__('admin.facility.fields.unit'))
                ->entity(Unit::class)
                // Clamped: asset_id is ->live() and client-supplied, so the raw value would
                // enumerate an invisible property's units.
                ->modifyOptionsQuery(fn ($query, Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                    ? $query->where('asset_id', $assetId)
                    : $query->whereRaw('1 = 0'))
                ->disabled($locked),
            Select::make('equipment_id')
                ->live()
                ->afterStateUpdated(function ($state, Set $set, string $operation): void {
                    // Only on CREATE: re-picking the machine on an existing job must not silently
                    // re-grade a priority someone already decided.
                    if ($operation !== 'create' || ! $state) {
                        return;
                    }

                    $equipment = Equipment::find($state);

                    if ($equipment instanceof Equipment) {
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
            // التخصص — a ROW now, not a translation key. Required with NO default: the trade
            // routes the work, decides which vendors may be dispatched and is the axis every
            // maintenance-spend report groups by, and defaulting it to "Other" would make it
            // meaningless on exactly the jobs nobody stopped to think about.
            Select::make('trade_id')
                ->label(__('admin.facility.fields.trade'))
                ->options(fn (?FacilityWorkOrder $record) => Trade::options($record?->trade_id))
                ->required()
                ->native(false)
                ->searchable()
                // Live so the vendor picker below regroups the moment the trade is chosen — the
                // coordinator sees who does HVAC without leaving the form.
                ->live()
                ->helperText(__('admin.facility.help.trade'))
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
            EntitySelect::make('department_id')
                ->label(__('admin.facility.fields.department'))
                ->entity(Department::class)
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Select::make('vendor_id')
                ->label(__('admin.facility.fields.vendor'))
                // Only dispatchable vendors (active + COI not lapsed); the saving guard is the
                // real gate. Grouped by whether they do the chosen trade — a suggestion, not a
                // filter, so an unusual but legitimate pick is still possible.
                ->options(fn ($record, Get $get) => Vendor::assignableOptions(
                    $record?->vendor_id,
                    filled($get('trade_id')) ? (int) $get('trade_id') : null,
                ))
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Textarea::make('notes')
                ->label(__('admin.facility.fields.notes'))
                ->rows(2)
                ->columnSpanFull()
                ->disabled($locked),
            // Evidence for the job. NOT disabled by `$locked`: a photograph is the one thing an
            // engineer legitimately adds after the fact — the job is done, the phone is in their
            // pocket, and refusing the upload because the order reached a terminal state is how a
            // record ends up with no evidence at all. The commercial fields stay frozen.
            SpatieMediaLibraryFileUpload::make('evidence')
                ->label(__('admin.facility.fields.evidence'))
                ->helperText(__('admin.facility.helpers.evidence'))
                ->collection('evidence')
                ->multiple()
                ->appendFiles()
                ->reorderable()
                ->downloadable()
                ->openable()
                ->preserveFilenames()
                ->acceptedFileTypes(['image/*', 'application/pdf'])
                ->maxSize(10240)
                ->maxFiles(10)
                ->columnSpanFull(),
        ]);
    }
}
