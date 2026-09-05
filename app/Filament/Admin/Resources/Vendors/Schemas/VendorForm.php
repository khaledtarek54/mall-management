<?php

namespace App\Filament\Admin\Resources\Vendors\Schemas;

use App\Models\TaxCode;
use App\Models\Trade;
use App\Models\Vendor;
use App\Support\Filament\CustomFieldsSchema;
use App\Support\Pdf\DocumentLocale;
use App\Support\WithholdingTax;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.vendor_details'))
                ->columns(2)
                ->components([
                    // See TenantForm — allocated, shown, never typed.
                    TextInput::make('code')
                        ->label(__('admin.fields.vendor_code'))
                        ->placeholder(__('admin.fields.code_auto'))
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('name')
                        ->label(__('admin.tables.vendor.name'))
                        ->required()
                        ->maxLength(200),
                    TextInput::make('legal_name')
                        ->label(__('admin.fields.legal_name'))
                        ->maxLength(200),
                    Select::make('type')
                        ->label(__('admin.tables.vendor.type'))
                        ->options(fn () => __('admin.enums.vendor_type'))
                        ->required()
                        ->default('service_provider')
                        ->native(false),
                    // **What this vendor actually does.** `type` says what KIND of counterparty
                    // they are (contractor / supplier / …); it has never said what work they can
                    // take, which is why the picker on an HVAC fault used to offer the stationery
                    // supplier. Multiple, because a facilities company does HVAC and electrical
                    // and registering them twice is not an answer.
                    Select::make('trades')
                        ->label(__('admin.facility.fields.trades'))
                        ->relationship('trades')
                        ->options(fn (?Vendor $record) => Trade::options($record?->trades->pluck('id')->all()))
                        ->multiple()
                        ->preload()
                        ->native(false)
                        ->helperText(__('admin.facility.help.vendor_trades')),

                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.vendor'))
                        ->required()
                        ->default('active')
                        ->native(false),
                    TextInput::make('tax_id')
                        ->label(__('admin.fields.tax_id'))
                        ->maxLength(50),
                    // خصم وإضافة. The rate is NOT typed here — it is the code's, from the
                    // operator's catalogue. Blank = use the portfolio default; the toggle below is
                    // the separate statement "this supplier is outside withholding altogether",
                    // which must survive a later change to that default.
                    Select::make('withholding_tax_code')
                        ->label(__('admin.vendors.wht.code'))
                        ->options(fn () => TaxCode::options(
                            TaxCode::PURCHASES,
                            families: [TaxCode::FAMILY_WITHHOLDING],
                        ))
                        ->native(false)
                        ->placeholder(__('admin.vendors.wht.use_default'))
                        ->helperText(__('admin.vendors.wht.code_hint'))
                        ->disabled(fn (Get $get) => (bool) $get('withholding_exempt'))
                        ->visible(fn () => WithholdingTax::enabled()),

                    Toggle::make('withholding_exempt')
                        ->label(__('admin.vendors.wht.exempt'))
                        ->helperText(__('admin.vendors.wht.exempt_hint'))
                        ->live()
                        ->visible(fn () => WithholdingTax::enabled()),
                    TextInput::make('email')
                        ->label(__('admin.fields.email'))
                        ->email()
                        // 255, with the column — the length every other party table in this
                        // application holds, and one over RFC 5321's cap on a path.
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label(__('admin.fields.phone'))
                        ->tel()
                        ->maxLength(50),
                    // Which language this supplier's purchase orders and withholding certificates
                    // are issued in. Blank is the honest default — it means nobody has asked, and
                    // the document then follows whoever is producing it.
                    Select::make('locale')
                        ->label(__('admin.fields.locale'))
                        ->helperText(__('admin.helpers.vendor_locale'))
                        ->options(DocumentLocale::options())
                        ->placeholder(__('admin.fields.locale_unset'))
                        ->native(false),
                    TextInput::make('city')
                        ->label(__('admin.fields.city') ?: 'City')
                        ->maxLength(100),
                    Textarea::make('address')
                        ->label(__('admin.fields.address'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.notes'))
                ->collapsible()
                ->collapsed()
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            // The operator's own fields for this record type (D-7). Renders nothing at all
            // until somebody defines one, so a fresh install is unchanged.
            ...CustomFieldsSchema::form('vendor'),

        ]);
    }
}
