<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Schemas;

use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\TenantScope;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
                        ->default(fn () => MaintenanceRequest::generateReference('HW'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))
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
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.maintenance_category'))
                        ->default('other')
                        ->required()
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
                        ->required()
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
                        ->minDate(fn (?MaintenanceRequest $record) => $record?->created_at?->startOfDay() ?? today())
                        ->validationMessages([
                            'after_or_equal' => __('admin.validation.maintenance_resolution_after_creation'),
                        ]),
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
