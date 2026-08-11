<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Schemas;

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Support\PostingRoles;
use App\Support\Vat;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ChargeCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.charge_code'))
                ->description(__('admin.helpers.charge_code_section'))
                ->columns(2)
                ->components([
                    TextInput::make('code')
                        ->label(__('admin.fields.charge_code'))
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[a-z][a-z0-9_]*$/')
                        // Locked once it exists. This value is stored on every invoice line ever
                        // billed under it, so renaming it would orphan the history — the label
                        // below is what you change when the name is wrong.
                        ->disabled(fn (?ChargeCode $record) => $record !== null)
                        ->dehydrated()
                        ->helperText(__('admin.helpers.charge_code')),

                    Select::make('posting_role')
                        ->label(__('admin.fields.posting_role'))
                        ->options(fn () => PostingRoles::groupedOptions())
                        ->searchable()
                        ->native(false)
                        ->placeholder(__('admin.charge_codes.unmapped'))
                        ->helperText(fn (Get $get) => ($group = PostingRoles::group((string) $get('posting_role')))
                            ? __('admin.helpers.posting_role_expects', ['group' => PostingRoles::groupLabel($group)])
                            : __('admin.helpers.charge_code_role')),

                    // Taxability is the accountant's ruling and belongs beside the code it applies
                    // to — the same place Yardi puts it (a `Tax` flag on the charge code). It was
                    // a PHP array until 2026-08-11, then a treatment + rate typed here, and is now
                    // a reference to the tax itself: the RATE and the day it came into force belong
                    // to the tax, not to each of the twelve charge codes that happen to use it.
                    Select::make('tax_code')
                        ->label(__('admin.fields.tax_code'))
                        ->options(fn () => TaxCode::options(TaxCode::SALES))
                        ->default(Vat::STANDARD_TAX_CODE)
                        ->native(false)
                        ->live()
                        // Blank is a real answer — an operator-added code nobody has ruled on yet —
                        // so this is not `required()`. `Vat::rateForType()` puts an unclassified
                        // code on the floor, which is the same place an unseeded catalogue lands.
                        ->placeholder(__('admin.charge_codes.tax_unclassified'))
                        ->hintIcon('heroicon-o-information-circle')
                        // The rate is the whole reason for the choice, so it is shown next to it
                        // rather than left a screen away. Resolved for TODAY: this is what a charge
                        // originated now would bill, which is the question being answered here.
                        ->hint(fn (Get $get) => ($code = $get('tax_code'))
                            ? self::rateHint((string) $code)
                            : null)
                        ->helperText(__('admin.helpers.charge_code_tax_code')),

                    TextInput::make('name_en')
                        ->label(__('admin.fields.name_en'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('name_ar')
                        ->label(__('admin.fields.name_ar'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('sort_order')
                        ->label(__('admin.fields.sort_order'))
                        ->numeric()
                        ->default(100)
                        ->minValue(0)
                        ->maxValue(999)
                        ->helperText(__('admin.helpers.charge_code_sort')),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true)
                        // A code the billing engine has logic for cannot be switched off: CAM
                        // recovery and percentage rent are excluded from the anti-double-bill
                        // probe, late fees and NSF fees settle last. Disabling one would not stop
                        // the engine using it — it would only hide it from the picker, which is
                        // the worst of both.
                        ->disabled(fn (?ChargeCode $record) => $record !== null
                            && in_array($record->code, InvoiceItemType::values(), true))
                        ->helperText(fn (?ChargeCode $record) => $record !== null
                            && in_array($record->code, InvoiceItemType::values(), true)
                                ? __('admin.helpers.charge_code_system')
                                : __('admin.helpers.charge_code_active')),
                ]),
        ]);
    }

    /**
     * "14.00% — in force from 01/07/2017", beside the picker.
     *
     * A tax code with no rung yet reads as such rather than as 0: an accountant who has added the
     * code but not the rate needs to see that, and a silent "0%" is exactly the wrong reassurance.
     */
    private static function rateHint(string $taxCode): ?string
    {
        $rate = TaxCode::rateOn($taxCode);

        if ($rate === null) {
            return __('admin.charge_codes.tax_no_rate');
        }

        return __('admin.charge_codes.tax_rate_hint', ['rate' => number_format($rate, 2)]);
    }
}
