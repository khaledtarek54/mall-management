<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Models\Department;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\User;
use App\Support\TenantScope;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MaintenanceRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.maintenance_request'))
                ->columns(3)
                ->components([
                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->default(fn () => TenantRequest::generateReference('AW'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('request_type')
                        ->label(__('admin.fields.request_type'))
                        ->options(fn () => TenantRequestType::options())
                        ->default(TenantRequestType::default()->value)
                        ->required()
                        ->native(false)
                        ->live()
                        // Keep the reference prefix in step with the chosen type,
                        // and clear a now-irrelevant sub-category on type change.
                        ->afterStateUpdated(function ($state, Set $set) {
                            $type = TenantRequestType::tryFrom((string) $state) ?? TenantRequestType::default();
                            $set('reference', TenantRequest::generateReference('AW', $type->referencePrefix()));
                            $set('category', null);
                        })
                        // Type is fixed once a request exists (it would invalidate
                        // the reference + routing); editable only on create.
                        ->disabledOn('edit'),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->options(fn () => TenantScope::selectableTenantOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        // Required for a portal request (always a known tenant), or whenever no
                        // caller name is given — a request must record who reported it (the same
                        // invariant TenantRequest::booted enforces server-side).
                        ->required(fn (Get $get) => $get('channel') === TenantRequest::SELF_SERVICE_CHANNEL || blank($get('caller_name'))),
                    // Intake for someone who is NOT a registered tenant — only on a staff channel.
                    TextInput::make('caller_name')
                        ->label(__('admin.maintenance.caller.name'))
                        ->maxLength(255)
                        ->live()
                        ->helperText(__('admin.maintenance.caller.section_hint'))
                        ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL)
                        ->required(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL && blank($get('tenant_id'))),
                    TextInput::make('caller_phone')
                        ->label(__('admin.maintenance.caller.phone'))
                        ->tel()
                        ->maxLength(50)
                        ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL),
                    Textarea::make('caller_notes')
                        ->label(__('admin.maintenance.caller.notes'))
                        ->rows(2)
                        ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL),
                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->options(function () {
                            $assetIds = TenantScope::visibleAssetIds();

                            return Unit::with('asset')
                                ->when($assetIds, fn ($q) => $q->whereIn('asset_id', $assetIds))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Unit $u) => [$u->id => $u->fullName()]);
                        })
                        ->searchable()
                        ->required(),
                    Select::make('priority')
                        ->label(__('admin.fields.priority'))
                        ->options(fn () => __('admin.enums.maintenance_priority'))
                        ->default('medium')
                        ->required()
                        ->native(false),
                    Select::make('category')
                        ->label(__('admin.fields.subcategory'))
                        // Options + visibility follow the chosen type: each type
                        // exposes its own sub-categories (electrical…, parking…,
                        // lease_copy…); types with none hide the field entirely.
                        ->options(fn (Get $get) => collect(
                            (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                        )->mapWithKeys(fn (string $s) => [$s => __("admin.enums.tenant_request_subcategory.{$s}")]))
                        ->visible(fn (Get $get) => filled(
                            (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                        ))
                        ->native(false),
                    Select::make('channel')
                        ->label(__('admin.fields.channel'))
                        ->options(fn () => __('admin.enums.maintenance_channel'))
                        ->default('portal')
                        ->required()
                        ->native(false)
                        ->live() // drives the caller-intake fields + tenant requiredness
                        ->helperText(__('admin.fields.channel_helper')),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.maintenance_request'))
                        ->default('submitted')
                        // Read-only: status changes go through the Change-Status action
                        // (TenantRequestService::transition) — the state machine that
                        // validates the hop, stamps resolved_at/closed_at, and notifies.
                        // A raw form write would skip all of that (and break auto-close).
                        ->disabled()
                        ->dehydrated(false)
                        ->native(false),
                ]),

            Section::make(__('admin.sections.maintenance_details'))
                ->columns(1)
                ->components([
                    TextInput::make('title')
                        ->label(__('admin.fields.maintenance_title'))
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),
                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.assignment'))
                ->columns(2)
                ->components([
                    Select::make('department_id')
                        ->label(__('admin.resources.department.singular'))
                        ->options(fn () => Department::selectableOptions())
                        ->searchable()
                        ->placeholder(__('admin.fields.unassigned'))
                        ->native(false),
                    // The facility zone this request sits in — inherited from the unit on intake
                    // (TenantRequest::creating), so it's shown read-only here. Disabled +
                    // non-dehydrated: the derivation owns the value, the form only surfaces it.
                    Select::make('area_id')
                        ->label(__('admin.fields.area'))
                        ->relationship('area', 'name')
                        ->disabled()
                        ->dehydrated(false)
                        ->native(false)
                        ->placeholder(__('admin.fields.area_auto')),
                    Select::make('assigned_to')
                        ->label(__('admin.fields.assigned_to'))
                        ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder(__('admin.fields.unassigned')),
                    Select::make('assigned_to_vendor_id')
                        ->label(__('admin.fields.assigned_vendor') ?: 'External Vendor')
                        ->relationship('assignedVendor', 'name', fn ($query) => $query->where('status', 'active'))
                        ->searchable()
                        ->preload()
                        ->placeholder('—'),
                    DateTimePicker::make('target_resolution_at')
                        ->label(__('admin.fields.target_resolution_at'))
                        ->native(false)
                        ->seconds(false)
                        // A resolution target can't predate the request itself.
                        // On edit, floor it at the record's creation date; on
                        // create the row doesn't exist yet, so floor at today.
                        ->minDate(fn (?TenantRequest $record) => $record?->created_at?->startOfDay() ?? today())
                        ->validationMessages([
                            'after_or_equal' => __('admin.validation.maintenance_resolution_after_creation'),
                        ]),
                    DateTimePicker::make('scheduled_from')
                        ->label(__('admin.fields.scheduled_from'))
                        ->native(false)
                        ->seconds(false),
                    DateTimePicker::make('scheduled_to')
                        ->label(__('admin.fields.scheduled_to'))
                        ->native(false)
                        ->seconds(false)
                        ->afterOrEqual('scheduled_from'),
                ]),

            Section::make(__('admin.sections.resolution'))
                ->collapsed()
                ->collapsible()
                ->columns(1)
                ->components([
                    Textarea::make('resolution_notes')
                        ->label(__('admin.fields.resolution_notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.attachments'))
                ->description(__('admin.sections.attachments_description'))
                ->collapsible()
                ->components([
                    SpatieMediaLibraryFileUpload::make('attachments')
                        ->label(__('admin.fields.attachments'))
                        ->collection('attachments')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        // Images + PDF only — these are what the tenant app can
                        // render/preview. Wider types (video, office docs) were
                        // dropped per QA so the mobile viewer never gets a file
                        // it can't open.
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(10240)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
