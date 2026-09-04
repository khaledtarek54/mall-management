<?php

namespace App\Filament\Admin\Resources\Assets\Schemas;

use App\Support\Filament\CustomFieldsSchema;
use App\Support\ValueSets;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    // The pair is a LOAD FACTOR, not two independent numbers — see
                    // areasMustFitInsideTheBuilding() below. The rule is on BOTH fields, because
                    // either one can be the one that moves.
                    TextInput::make('total_area_sqm')
                        ->label(__('admin.fields.total_area_sqm'))
                        ->numeric()
                        ->minValue(0)
                        ->rules([self::areasMustFitInsideTheBuilding()])
                        ->suffix('m²'),
                    TextInput::make('leasable_area_sqm')
                        ->label(__('admin.fields.leasable_area_sqm'))
                        ->numeric()
                        ->minValue(0)
                        ->rules([self::areasMustFitInsideTheBuilding()])
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

    /**
     * A property cannot let more space than it has.
     *
     * The two fields are a LOAD FACTOR and nothing enforced it. `Asset::leasableEfficiencyPct()` is
     * `leasable ÷ gross` — the figure the properties table prints beside them, under a docblock
     * saying "a number far outside that usually means one of the two figures is wrong" — and
     * `CamReconciliationService` takes the declared leasable area as the GLA DENOMINATOR of the
     * whole recovery, so an inflated one shrinks every tenant's share and the mall under-recovers.
     * Measured at HEAD: 800 gross against 1,000 leasable saved with no complaint and the load factor
     * printed 125%.
     *
     * ON BOTH FIELDS, one closure. Guarding only the leasable side leaves the gross area freely
     * editable downward, which is the same mistake through the other door; two closures would be two
     * copies of one comparison, free to drift.
     *
     * **BLANK IS NOT ZERO.** Both columns are optional and a mall that has only ever recorded its GLA
     * is the ordinary case — `leasableEfficiencyPct()` returns null rather than 0% for exactly that
     * reason — so the rule stands down unless BOTH figures are stated. That is also why this is not
     * Filament's own `->lte('total_area_sqm')`: it resolves the state path correctly, but Laravel's
     * `lte` compares a stated value against a NULL sibling by falling through to `isSameType()`,
     * which is false, so it would refuse every property whose gross area has not been measured.
     *
     * The form is the only door: there is no Asset importer, and nothing else in `app/` writes
     * either column.
     */
    private static function areasMustFitInsideTheBuilding(): Closure
    {
        return static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $gross = $get('total_area_sqm');
            $leasable = $get('leasable_area_sqm');

            if (blank($gross) || blank($leasable)) {
                return;
            }

            if (round((float) $leasable, 2) > round((float) $gross, 2)) {
                $fail(__('admin.validation.leasable_area_exceeds_gross', [
                    'leasable' => number_format((float) $leasable, 2),
                    'gross' => number_format((float) $gross, 2),
                ]));
            }
        };
    }
}
