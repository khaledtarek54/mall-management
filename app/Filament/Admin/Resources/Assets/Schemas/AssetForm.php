<?php

namespace App\Filament\Admin\Resources\Assets\Schemas;

use App\Support\Filament\CustomFieldsSchema;
use App\Support\ValueSets;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

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
                        ->maxLength(255)
                        ->default('Cairo'),
                    TextInput::make('country')
                        ->label(__('admin.fields.country'))
                        ->required()
                        ->maxLength(255)
                        ->default('Egypt'),
                    // The rule this and the vendor contract follow: a currency field survives only
                    // where the value is PRINTED — this one leads the owner statement. It is shown
                    // rather than hidden so the operator can see what their statements are
                    // denominated in, and read-only because the system has no FX to honour any
                    // other answer. `readOnly()` is a UI truth, so the set is enforced server-side
                    // too — a crafted payload would otherwise reach the model guard as a 403-ish
                    // toast instead of a field error.
                    TextInput::make('currency')
                        ->label(__('admin.fields.currency'))
                        ->required()
                        ->default('EGP')
                        ->readOnly()
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.asset_currency'))
                        ->rules([Rule::in(ValueSets::allowed('assets', 'currency'))])
                        ->maxLength(3),
                ]),
            Section::make(__('admin.sections.area'))
                ->columns(2)
                ->components([
                    TextInput::make('total_area_sqm')
                        ->label(__('admin.fields.total_area_sqm'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('m²'),
                    TextInput::make('leasable_area_sqm')
                        ->label(__('admin.fields.leasable_area_sqm'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('m²'),
                ]),
            Section::make(__('admin.sections.status'))
                ->components([
                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true),
                    // Beside `is_active` on purpose, because the two were confused: before this
                    // flag the only way to keep a mall out of the shopper feed was to deactivate
                    // it, which also empties the property switcher and hides its units. The
                    // helper text says what the switch publishes, because a control that makes
                    // something public must say so at the moment it is flipped.
                    Toggle::make('is_publicly_listed')
                        ->label(__('admin.fields.is_publicly_listed'))
                        ->helperText(__('admin.fields.is_publicly_listed_helper'))
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
            // The operator's own fields for this record type (D-7). Renders nothing at all
            // until somebody defines one, so a fresh install is unchanged.
            ...CustomFieldsSchema::form('asset'),

        ]);
    }
}
