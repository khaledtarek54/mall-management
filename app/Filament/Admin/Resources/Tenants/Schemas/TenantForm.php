<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Models\RetailCategory;
use App\Models\Tenant;
use App\Support\EgyptGovernorates;
use App\Support\Filament\CustomFieldsSchema;
use App\Support\FormTab;
use App\Support\Pdf\DocumentLocale;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        // One tab per concern through App\Support\FormTab, so each carries a badge counting
        // the validation errors INSIDE it (UX-13) — Filament v4 has no error indicator on Tabs,
        // and without one a blank required field on an unseen tab refuses the form with nothing
        // visible to fix.
        return $schema->columns(1)->components([
            Tabs::make('tenant')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make('admin.sections.tenant_information', [

                        // Allocated, not typed — the same treatment an invoice number gets. It is
                        // shown because it is the thing an operator reads out on the phone and types
                        // into a search box, and `dehydrated()` keeps a code carried in from another
                        // system on the row through an edit.
                        TextInput::make('code')
                            ->label(__('admin.fields.tenant_code'))
                            ->placeholder(__('admin.fields.code_auto'))
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('name')
                            ->label(__('admin.fields.brand_name'))
                            ->required()
                            ->maxLength(Tenant::FIELD_MAX['name']),
                        TextInput::make('legal_name')
                            ->label(__('admin.fields.legal_name'))
                            ->maxLength(Tenant::FIELD_MAX['legal_name']),
                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options([
                                'individual' => __('admin.fields.individual'),
                                'company' => __('admin.fields.company'),
                            ])
                            ->default('company')
                            ->required()
                            // Reactive: the tax-address section below is required for a
                            // company and irrelevant for an individual, and both closures
                            // read this value.
                            ->live()
                            ->native(false),
                        Select::make('status')
                            ->label(__('admin.tables.common.status'))
                            ->options(fn () => __('admin.statuses.tenant'))
                            ->default('active')
                            ->required()
                            ->native(false),
                        TextInput::make('tax_id')
                            ->label(__('admin.fields.tax_id'))
                            ->maxLength(50)
                            // Egyptian Tax Registration Number: 9 digits, optional
                            // dashes (`XXX-XXX-XXX`). Required at ETA-submission
                            // time for business tenants — validating here surfaces
                            // bad data before billing instead of letting ETA reject
                            // it (audit M15 F-59 / D-44).
                            ->regex('/^\d{3}-?\d{3}-?\d{3}$/')
                            ->validationMessages([
                                'regex' => __('admin.validation.tenant_tax_id_format'),
                            ])
                            ->placeholder('123-456-789')
                            ->helperText(__('admin.helpers.tenant_tax_id_format')),
                        TextInput::make('national_id')
                            ->label(__('admin.fields.national_id'))
                            ->maxLength(20),
                        TextInput::make('commercial_register')
                            ->label(__('admin.fields.commercial_register'))
                            ->maxLength(50),
                    ])->columns(2),
                    FormTab::make('admin.sections.contact', [

                        TextInput::make('email')
                            ->label(__('admin.fields.email'))
                            ->email()
                            ->maxLength(Tenant::FIELD_MAX['email']),
                        TextInput::make('phone')
                            ->label(__('admin.fields.phone'))
                            ->tel()
                            ->maxLength(Tenant::FIELD_MAX['phone']),
                        TextInput::make('whatsapp')
                            ->label(__('admin.fields.whatsapp'))
                            ->tel()
                            ->maxLength(20),
                        // Which language this tenant's invoices, credit notes, receipts and
                        // statements are issued in. It is a correspondence fact, not a UI one —
                        // their accountant files what we send them, whatever language the operator
                        // happens to be working in. Blank is the honest default: it means nobody has
                        // asked, and the document then follows whoever is producing it.
                        Select::make('locale')
                            ->label(__('admin.fields.locale'))
                            ->helperText(__('admin.helpers.document_language'))
                            ->options(DocumentLocale::options())
                            ->placeholder(__('admin.fields.locale_unset'))
                            ->native(false),
                        TextInput::make('contact_person')
                            ->label(__('admin.fields.contact_person'))
                            ->maxLength(Tenant::FIELD_MAX['contact_person']),
                        TextInput::make('contact_person_phone')
                            ->label(__('admin.fields.contact_person_phone'))
                            ->tel()
                            ->maxLength(20),
                        Textarea::make('address')
                            ->label(__('admin.fields.address'))
                            ->helperText(__('admin.helpers.tenant_address'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tenant_address'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),
                    // ETA files the buyer's address in PARTS and validates them, so they cannot
                    // be carved out of the freeform address above at submission time. Required
                    // only for BUSINESS tenants — that is who gets filed (EtaJsonBuilder refuses
                    // a business submission without them, rather than filing a guess).
                    FormTab::make('admin.sections.tax_address', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.tax_address_description'))
                            ->columnSpanFull(),

                        Select::make('address_governorate')
                            ->label(__('admin.fields.address_governorate'))
                            // A fixed list, because "Cairo", "cairo" and "القاهرة" are three
                            // spellings ETA does not treat alike.
                            ->options(fn () => EgyptGovernorates::options())
                            ->searchable()
                            ->native(false)
                            ->required(fn (Get $get) => $get('type') === 'company'),
                        TextInput::make('address_city')
                            ->label(__('admin.fields.address_city'))
                            ->maxLength(255)
                            ->required(fn (Get $get) => $get('type') === 'company'),
                        TextInput::make('address_street')
                            ->label(__('admin.fields.address_street'))
                            ->maxLength(255)
                            ->required(fn (Get $get) => $get('type') === 'company'),
                        TextInput::make('address_building_number')
                            ->label(__('admin.fields.address_building_number'))
                            ->maxLength(50)
                            ->required(fn (Get $get) => $get('type') === 'company'),
                    ])->columns(2),
                    // ---- Module 36: who this retailer is to a SHOPPER, as opposed to who we
                    // invoice. Its own tab rather than a collapsed section — the form became tabs
                    // (UX-13) while this was in flight, and a collapsed Section inside a tab is
                    // two things to open for one field. Placed after the tax address and before
                    // documents: it is marketing's, filled in once, and nothing in the leasing or
                    // billing flow depends on it.
                    FormTab::make('admin.sections.store_directory', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.store_directory_description'))
                            ->columnSpanFull(),
                        TextInput::make('trade_name')
                            ->label(__('admin.fields.trade_name'))
                            ->helperText(__('admin.fields.trade_name_hint'))
                            ->maxLength(255),
                        TextInput::make('trade_name_ar')
                            ->label(__('admin.fields.trade_name_ar'))
                            ->maxLength(255),
                        Select::make('retail_category')
                            ->label(__('admin.fields.retail_category'))
                            // The catalogue, not the const: an operator-added category has no lang
                            // key and would render as its raw code on the very screen that offers it.
                            ->options(fn () => RetailCategory::options())
                            ->native(false)
                            ->searchable(),
                        // Boolean → NOT NULL column. A Toggle always dehydrates a bool, and the model
                        // carries the default too, so a form that never renders it cannot send null.
                        Toggle::make('is_listed')
                            ->label(__('admin.fields.is_listed'))
                            ->helperText(__('admin.fields.is_listed_hint'))
                            ->default(true),
                        Textarea::make('public_description')
                            ->label(__('admin.fields.public_description'))
                            ->rows(2)
                            ->maxLength(500),
                        Textarea::make('public_description_ar')
                            ->label(__('admin.fields.public_description_ar'))
                            ->rows(2)
                            ->maxLength(500),
                        TextInput::make('website_url')
                            ->label(__('admin.fields.website_url'))
                            ->url()
                            ->maxLength(255),
                        TextInput::make('instagram_handle')
                            ->label(__('admin.fields.instagram_handle'))
                            ->prefix('@')
                            ->maxLength(60),
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->label(__('admin.fields.store_logo'))
                            ->helperText(__('admin.fields.store_logo_hint'))
                            // PUBLIC disk — the one public thing about a retailer. The `documents`
                            // collection below stays private; separate collections are exactly what
                            // lets the brand mark be public without the paperwork following it.
                            ->collection(Tenant::LOGO_COLLECTION)
                            ->image()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->columnSpanFull(),
                    ])->columns(2),
                    FormTab::make('admin.sections.documents', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.documents_description'))
                            ->columnSpanFull(),
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
                ]),
            // The operator's own fields for this record type (D-7). Renders nothing at all
            // until somebody defines one, so a fresh install is unchanged.
            ...CustomFieldsSchema::form('tenant'),

        ]);
    }
}
