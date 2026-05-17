<?php

namespace App\Filament\Admin\Resources\Leases\Schemas;

use App\Models\Lease;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.lease_details'))
                ->columns(3)
                ->components([
                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->default(fn () => Lease::generateReference('HW'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->relationship('unit', 'code')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->relationship('tenant', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')->label(__('admin.fields.brand_name'))->required(),
                            TextInput::make('phone')->label(__('admin.fields.phone'))->tel(),
                            TextInput::make('email')->label(__('admin.fields.email'))->email(),
                        ]),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.lease'))
                        ->default('draft')
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('admin.sections.term'))
                ->columns(3)
                ->components([
                    DatePicker::make('commencement_date')
                        ->label(__('admin.fields.commencement_date'))
                        ->required()
                        ->native(false),
                    TextInput::make('term_months')
                        ->label(__('admin.fields.term_months'))
                        ->numeric()
                        ->default(36)
                        ->required()
                        ->suffix(__('admin.fields.months')),
                    DatePicker::make('expiry_date')
                        ->label(__('admin.fields.expiry_date'))
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('admin.sections.financial_terms'))
                ->columns(3)
                ->components([
                    TextInput::make('base_rent_monthly')
                        ->label(__('admin.fields.base_rent_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->required(),
                    TextInput::make('service_charge_monthly')
                        ->label(__('admin.fields.service_charge_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0),
                    TextInput::make('security_deposit')
                        ->label(__('admin.fields.security_deposit'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0),
                    TextInput::make('escalation_rate')
                        ->label(__('admin.fields.escalation_rate'))
                        ->numeric()
                        ->suffix('%')
                        ->default(7),
                    Select::make('escalation_type')
                        ->label(__('admin.fields.escalation_type'))
                        ->options(fn () => __('admin.enums.escalation_type'))
                        ->default('fixed_percent')
                        ->native(false),
                    TextInput::make('payment_terms_days')
                        ->label(__('admin.fields.payment_terms_days'))
                        ->numeric()
                        ->default(7)
                        ->suffix(__('admin.fields.days')),
                    Toggle::make('security_deposit_received')
                        ->label(__('admin.fields.security_deposit_received'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.percentage_rent'))
                ->description(__('admin.sections.percentage_rent_description'))
                ->columns(3)
                ->collapsed()
                ->collapsible()
                ->components([
                    Toggle::make('has_percentage_rent')
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('percentage_rent_threshold')
                        ->prefix('EGP')
                        ->numeric()
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                    TextInput::make('percentage_rent_rate')
                        ->suffix('%')
                        ->numeric()
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                ]),

            Section::make(__('admin.sections.notes'))
                ->collapsed()
                ->collapsible()
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
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
