<?php

namespace App\Filament\Admin\Resources\TaxCodes\Schemas;

use App\Models\TaxCode;
use App\Support\PostingRoles;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TaxCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.tax_code'))
                ->description(__('admin.helpers.tax_code_section'))
                ->columns(2)
                ->components([
                    TextInput::make('code')
                        ->label(__('admin.fields.tax_code'))
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[A-Z][A-Z0-9_]*$/')
                        // Locked once it exists, for the same reason a charge code is: charge codes
                        // reference this string, so renaming it would silently un-classify every
                        // supply pointed at it. The labels below are what you change.
                        ->disabled(fn (?TaxCode $record) => $record !== null)
                        ->dehydrated()
                        ->helperText(__('admin.helpers.tax_code_code'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_code_code')),

                    Select::make('family')
                        ->label(__('admin.fields.tax_family'))
                        ->options(fn () => __('admin.enums.tax_family'))
                        ->default(TaxCode::FAMILY_VAT)
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.tax_family'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_family')),

                    Select::make('direction')
                        ->label(__('admin.fields.tax_direction'))
                        ->options(fn () => __('admin.enums.tax_direction'))
                        ->default(TaxCode::SALES)
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.tax_direction'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_direction')),

                    TextInput::make('invoice_label')
                        ->label(__('admin.fields.invoice_label'))
                        ->required()
                        ->maxLength(64)
                        ->helperText(__('admin.helpers.tax_invoice_label')),

                    TextInput::make('name_en')
                        ->label(__('admin.fields.name_en'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('name_ar')
                        ->label(__('admin.fields.name_ar'))
                        ->required()
                        ->maxLength(255),

                    Select::make('treatment')
                        ->label(__('admin.fields.tax_treatment'))
                        ->options(fn () => __('admin.enums.tax_treatment'))
                        ->default(TaxCode::STANDARD)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.helpers.tax_treatment'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_treatment')),

                    Select::make('posting_role')
                        ->label(__('admin.fields.posting_role'))
                        ->options(fn () => PostingRoles::groupedOptions())
                        ->searchable()
                        ->native(false)
                        ->placeholder(__('admin.tax_codes.no_role'))
                        // Exempt and zero-rated collect nothing, so there is nothing to post and a
                        // role would be a promise the tax cannot keep.
                        ->visible(fn (Get $get) => $get('treatment') === TaxCode::STANDARD)
                        ->helperText(__('admin.helpers.tax_posting_role'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_posting_role')),

                    TextInput::make('statutory_reference')
                        ->label(__('admin.fields.statutory_reference'))
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText(__('admin.helpers.statutory_reference'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.statutory_reference')),

                    TextInput::make('sort_order')
                        ->label(__('admin.fields.sort_order'))
                        ->numeric()
                        ->default(100)
                        ->minValue(0)
                        ->maxValue(999),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(false)
                        // Activation is the accountant's signal that the rate is entered and the
                        // account is wired. The model refuses to switch on a taxable code with no
                        // rung or no posting role, so this cannot become a code that appears in
                        // the picker and then bills nothing into nowhere.
                        ->helperText(__('admin.helpers.tax_code_active'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_code_active')),
                ]),
        ]);
    }
}
