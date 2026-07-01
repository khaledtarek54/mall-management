<?php

namespace App\Filament\Admin\Resources\VendorBills\Schemas;

use App\Models\Vendor;
use App\Models\VendorBill;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class VendorBillForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once past draft, the bill's terms are settled — lock the descriptive
        // fields (only payments/approval change it after that).
        $locked = fn (?VendorBill $record) => $record !== null && $record->status !== 'draft';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.vendor_bill_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.bill_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('vendor_id')
                        ->label(__('admin.fields.vendor'))
                        ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->disabled($locked),

                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->disabled($locked),

                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.vendor_bill_category'))
                        ->required()
                        ->native(false)
                        ->disabled($locked),

                    DatePicker::make('bill_date')
                        ->label(__('admin.fields.bill_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    DatePicker::make('due_date')
                        ->label(__('admin.fields.due_date'))
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('reference')
                        ->label(__('admin.fields.vendor_reference'))
                        ->maxLength(255)
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.bill_description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextInput::make('subtotal')
                        ->label(__('admin.fields.subtotal'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        ->disabled($locked),

                    TextInput::make('vat_amount')
                        ->label(__('admin.fields.vat_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        ->disabled($locked),

                    // Total is derived (subtotal + VAT) so it can never drift, and is
                    // always ≥ VAT — the journalizer books AP = total and expense = total − VAT.
                    TextInput::make('total')
                        ->label(__('admin.fields.total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated()
                        ->default(0),

                    TextInput::make('paid_amount')
                        ->label(__('admin.fields.paid_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('balance')
                        ->label(__('admin.fields.balance'))
                        ->prefix('EGP')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }

    protected static function syncTotal(Set $set, Get $get): void
    {
        $set('total', round((float) ($get('subtotal') ?? 0) + (float) ($get('vat_amount') ?? 0), 2));
    }
}
