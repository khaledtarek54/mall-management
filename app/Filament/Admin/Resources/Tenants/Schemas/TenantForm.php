<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.tenant_information'))
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->label(__('admin.fields.brand_name'))
                        ->required()
                        ->maxLength(100),
                    TextInput::make('legal_name')
                        ->label(__('admin.fields.legal_name'))
                        ->maxLength(150),
                    Select::make('type')
                        ->label(__('admin.fields.type'))
                        ->options([
                            'individual' => __('admin.fields.individual'),
                            'company' => __('admin.fields.company'),
                        ])
                        ->default('company')
                        ->required()
                        ->native(false),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.tenant'))
                        ->default('active')
                        ->required()
                        ->native(false),
                    TextInput::make('tax_id')
                        ->label(__('admin.fields.tax_id'))
                        ->maxLength(50),
                    TextInput::make('national_id')
                        ->label(__('admin.fields.national_id'))
                        ->maxLength(20),
                ]),
            Section::make(__('admin.sections.contact'))
                ->columns(2)
                ->components([
                    TextInput::make('email')
                        ->label(__('admin.fields.email'))
                        ->email()
                        ->maxLength(150),
                    TextInput::make('phone')
                        ->label(__('admin.fields.phone'))
                        ->tel()
                        ->maxLength(20),
                    TextInput::make('whatsapp')
                        ->label(__('admin.fields.whatsapp'))
                        ->tel()
                        ->maxLength(20),
                    TextInput::make('contact_person')
                        ->label(__('admin.fields.contact_person'))
                        ->maxLength(100),
                    TextInput::make('contact_person_phone')
                        ->label(__('admin.fields.contact_person_phone'))
                        ->tel()
                        ->maxLength(20),
                    Textarea::make('address')
                        ->label(__('admin.fields.address'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.sections.documents'))
                ->description(__('admin.sections.documents_description'))
                ->collapsible()
                ->components([
                    SpatieMediaLibraryFileUpload::make('documents')
                        ->label(__('admin.fields.documents'))
                        ->collection('documents')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->maxSize(10240)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
