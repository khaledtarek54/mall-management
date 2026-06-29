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
                        ->required(),
                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->options(function () {
                            $assetId = TenantScope::currentAssetId();

                            return Unit::with('asset')
                                ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
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
