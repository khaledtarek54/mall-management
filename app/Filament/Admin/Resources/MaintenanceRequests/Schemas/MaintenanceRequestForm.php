<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Schemas;

use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
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
                        ->options(fn () => Unit::with('asset')->orderBy('code')->get()
                            ->mapWithKeys(fn (Unit $u) => [$u->id => $u->fullName()]))
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
                    DateTimePicker::make('target_resolution_at')
                        ->label(__('admin.fields.target_resolution_at'))
                        ->native(false)
                        ->seconds(false),
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
                        ->acceptedFileTypes(['image/*', 'application/pdf', 'video/mp4'])
                        ->maxSize(10240)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
