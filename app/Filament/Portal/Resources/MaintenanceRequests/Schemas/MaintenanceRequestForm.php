<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests\Schemas;

use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class MaintenanceRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.maintenance_request'))
                ->description(__('admin.maintenance.portal_create_description'))
                ->columns(2)
                ->components([
                    TextInput::make('title')
                        ->label(__('admin.fields.maintenance_title'))
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),
                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.maintenance_category'))
                        ->default('other')
                        ->required()
                        ->native(false),
                    Select::make('priority')
                        ->label(__('admin.fields.priority'))
                        ->options(fn () => __('admin.enums.maintenance_priority'))
                        ->default('medium')
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.maintenance.urgent_warning')),
                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->options(function () {
                            /** @var Tenant|null $tenant */
                            $tenant = \App\Support\Portal::tenant();
                            if (! $tenant) {
                                return [];
                            }
                            return $tenant->leases()
                                ->with('unit')
                                ->get()
                                ->pluck('unit.code', 'unit.id')
                                ->filter()
                                ->all();
                        })
                        ->required()
                        ->columnSpanFull()
                        ->default(function () {
                            /** @var Tenant|null $tenant */
                            $tenant = \App\Support\Portal::tenant();
                            return $tenant?->activeLeases()->first()?->unit_id;
                        }),
                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->required()
                        ->rows(5)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.attachments'))
                ->description(__('admin.maintenance.attachments_helper'))
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
                        // Images + PDF only — keep tenant uploads to what the
                        // app and admin viewer can preview (QA-restricted).
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(10240)
                        ->maxFiles(5)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
