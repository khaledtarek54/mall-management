<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Schemas;

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
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
                        ->disabled(fn (?\App\Models\ChargeCode $record) => $record !== null)
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
                    // a PHP array until 2026-08-11, so exempting a new code needed a deploy.
                    Select::make('vat_treatment')
                        ->label(__('admin.fields.vat_treatment'))
                        ->options([
                            ChargeCode::VAT_STANDARD => __('admin.charge_codes.vat_standard'),
                            ChargeCode::VAT_EXEMPT => __('admin.charge_codes.vat_exempt'),
                            ChargeCode::VAT_ZERO_RATED => __('admin.charge_codes.vat_zero_rated'),
                        ])
                        ->default(ChargeCode::VAT_STANDARD)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.helpers.charge_code_vat_treatment')),

                    TextInput::make('vat_rate_override')
                        ->label(__('admin.fields.vat_rate_override'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        // Only a standard-rated supply can carry a rate; a rate typed against an
                        // exempt code would read as policy and do nothing.
                        ->visible(fn (Get $get) => $get('vat_treatment') === ChargeCode::VAT_STANDARD)
                        ->placeholder(fn () => number_format(Vat::standardRate(), 2).'%')
                        ->helperText(__('admin.helpers.charge_code_vat_override')),

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
                        ->disabled(fn (?\App\Models\ChargeCode $record) => $record !== null
                            && in_array($record->code, InvoiceItemType::values(), true))
                        ->helperText(fn (?\App\Models\ChargeCode $record) => $record !== null
                            && in_array($record->code, InvoiceItemType::values(), true)
                                ? __('admin.helpers.charge_code_system')
                                : __('admin.helpers.charge_code_active')),
                ]),
        ]);
    }
}
