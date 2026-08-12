<?php

namespace App\Filament\Admin\Resources\Expenses\Schemas;

use App\Models\Expense;
use App\Models\TaxCode;
use App\Support\CatalogueTaxRate;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        // A cancelled expense is a terminal record — read-only.
        $locked = fn (?Expense $record) => $record !== null && $record->status !== 'recorded';

        // The MONEY fields are locked the moment the expense exists, not merely once it is
        // cancelled: `recorded` IS posted here (there is no draft), so an edit would silently
        // re-derive a posted GL entry. The model refuses these in `updating`; this is the UI
        // mirror, so the operator sees a disabled field and the reason rather than a toast after
        // submitting. Same predicate on both layers, per the house rule that a rule stated twice
        // must be stated once. Correction path: cancel and re-enter.
        $moneyLocked = fn (?Expense $record) => $record !== null;

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.expense_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.expense_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->disabled($locked),

                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.vendor_bill_category'))
                        ->required()
                        ->native(false)
                        ->disabled($moneyLocked)
                        ->helperText(fn (?Expense $record) => $record !== null ? __('admin.errors.expense_immutable') : null),

                    Select::make('paid_from')
                        ->label(__('admin.fields.paid_from'))
                        ->options(fn () => __('admin.enums.expense_paid_from'))
                        ->default('cash')
                        ->native(false)
                        ->required()
                        ->disabled($moneyLocked)
                        ->helperText(fn (?Expense $record) => $record !== null ? __('admin.errors.expense_immutable') : null),

                    DatePicker::make('expense_date')
                        ->label(__('admin.fields.expense_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->maxLength(255)
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(3)
                ->components([
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        ->disabled($moneyLocked)
                        ->helperText(fn (?Expense $record) => $record !== null ? __('admin.errors.expense_immutable') : null),

                    // WHICH input tax this expense carried — it posts to `vat_recoverable`, the
                    // account the VAT return reads, so an unclassified figure is an unexplained
                    // reclaim.
                    Select::make('tax_code')
                        ->label(__('admin.fields.tax_code'))
                        ->options(fn () => TaxCode::options(TaxCode::PURCHASES))
                        ->native(false)
                        ->live()
                        ->placeholder(__('admin.charge_codes.tax_unclassified'))
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $derived = CatalogueTaxRate::deriveOnNet(
                                is_string($state) ? $state : null,
                                (float) ($get('amount') ?? 0),
                                is_string($get('expense_date')) ? $get('expense_date') : null,
                            );

                            if ($derived !== null) {
                                $set('vat_amount', $derived);
                                $set('tax_override_reason', null);
                                self::syncTotal($set, $get);
                            }
                        })
                        ->disabled($moneyLocked),

                    TextInput::make('vat_amount')
                        ->label(__('admin.fields.tax_total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        // Editable, unlike an invoice line's rate: a receipt states its own tax.
                        ->disabled($moneyLocked)
                        ->helperText(fn (?Expense $record) => $record !== null ? __('admin.errors.expense_immutable') : null),

                    TextInput::make('tax_override_reason')
                        ->label(__('admin.fields.tax_override_reason'))
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->required(fn (Get $get) => self::taxDeparts($get))
                        ->visible(fn (Get $get) => self::taxDeparts($get))
                        ->helperText(__('admin.helpers.purchase_tax_override_reason'))
                        ->disabled($moneyLocked),

                    // Total is derived (amount + VAT) so it can never drift — the model
                    // re-enforces it on every write; this is a live UX preview only.
                    TextInput::make('total')
                        ->label(__('admin.fields.total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated()
                        ->default(0),
                ]),
        ]);
    }

    protected static function syncTotal(Set $set, Get $get): void
    {
        $set('total', round((float) ($get('amount') ?? 0) + (float) ($get('vat_amount') ?? 0), 2));
    }

    /** Is the tax on this expense further from its code's figure than rounding explains? */
    protected static function taxDeparts(Get $get): bool
    {
        return CatalogueTaxRate::purchaseTaxDeparts(
            is_string($get('tax_code')) ? $get('tax_code') : null,
            (float) ($get('amount') ?? 0),
            (float) ($get('vat_amount') ?? 0),
            is_string($get('expense_date')) ? $get('expense_date') : null,
        );
    }
}
