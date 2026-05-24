<?php

namespace App\Filament\Admin\Resources\Vendors\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.vendor_details'))
                ->columns(2)
                ->components([
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
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.vendor'))
                        ->required()
                        ->default('active')
                        ->native(false),
                    TextInput::make('tax_id')
                        ->label(__('admin.fields.tax_id'))
                        ->maxLength(50),
                    TextInput::make('email')
                        ->label(__('admin.fields.email'))
                        ->email()
                        ->maxLength(200),
                    TextInput::make('phone')
                        ->label(__('admin.fields.phone'))
                        ->tel()
                        ->maxLength(50),
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
        ]);
    }
}
