<?php

namespace App\Filament\Admin\Resources\Assets\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.property_details'))
                ->columns(3)
                ->components([
                    TextInput::make('name')
                        ->label(__('admin.tables.asset.name'))
                        ->required()
                        ->maxLength(120),
                    TextInput::make('code')
                        ->label(__('admin.tables.asset.code'))
                        ->required()
                        ->maxLength(10)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),
                    Select::make('type')
                        ->label(__('admin.tables.asset.type'))
                        ->options(fn () => __('admin.enums.asset_type'))
                        ->default('mall')
                        ->required()
                        ->native(false),
                    Textarea::make('address')
                        ->label(__('admin.fields.address'))
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('city')
                        ->label(__('admin.tables.asset.city'))
                        ->required()
                        ->default('Cairo'),
                    TextInput::make('country')
                        ->required()
                        ->default('Egypt'),
                    TextInput::make('currency')
                        ->required()
                        ->default('EGP')
                        ->maxLength(3),
                ]),
            Section::make(__('admin.sections.area'))
                ->columns(2)
                ->components([
                    TextInput::make('total_area_sqm')
                        ->numeric()
                        ->suffix('m²'),
                    TextInput::make('leasable_area_sqm')
                        ->numeric()
                        ->suffix('m²'),
                ]),
            Section::make(__('admin.sections.status'))
                ->components([
                    Toggle::make('is_active')
                        ->default(true),
                ]),
            Section::make(__('admin.sections.branding'))
                ->description(__('admin.sections.branding_description'))
                ->columns(3)
                ->components([
                    SpatieMediaLibraryFileUpload::make('logo')
                        ->label(__('admin.fields.brand_logo'))
                        ->collection('logo')
                        ->image()
                        ->imageEditor()
                        ->maxSize(2048)
                        ->helperText(__('admin.fields.brand_logo_helper')),
                    SpatieMediaLibraryFileUpload::make('favicon')
                        ->label(__('admin.fields.brand_favicon'))
                        ->collection('favicon')
                        ->image()
                        ->maxSize(512)
                        ->helperText(__('admin.fields.brand_favicon_helper')),
                    ColorPicker::make('primary_color')
                        ->label(__('admin.fields.brand_primary_color'))
                        ->helperText(__('admin.fields.brand_primary_color_helper')),
                ]),
        ]);
    }
}
