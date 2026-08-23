<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Schemas;

use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RecurringExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // Never a bare EntitySelect on asset_id — the pinned component, per the property
                // isolation rule.
                PropertyField::make(),

                // Naming a supplier is what turns this schedule from "money leaving" into a
                // PAYABLE — `expenses` carries no vendor at all, so the presence of one IS the
                // statement. Blank keeps it an expense, which is what every existing schedule is.
                EntitySelect::make('vendor_id')
                    ->label(__('admin.fields.vendor'))
                    ->entity(Vendor::class)
                    ->live()
                    ->helperText(__('admin.recurring_expenses.help.vendor')),

                EntitySelect::make('vendor_contract_id')
                    ->label(__('admin.fields.vendor_contract'))
                    ->entity(VendorContract::class)
                    // Same narrowing as the vendor-bill form's own contract picker, including the
                    // portfolio-wide exception (`asset_id IS NULL` — a master agreement covering
                    // every mall), which the derived property scope cannot know about.
                    ->modifyOptionsQuery(function ($query, Get $get) {
                        $vendorId = $get('vendor_id');

                        if (blank($vendorId)) {
                            return $query->whereRaw('1 = 0');
                        }

                        $visible = TenantScope::visibleAssetIds();

                        return $query
                            ->where('vendor_id', $vendorId)
                            ->when($visible !== null, fn ($q) => $q->where(
                                fn ($w) => $w->whereIn('asset_id', $visible)->orWhereNull('asset_id'),
                            ));
                    })
                    ->visible(fn (Get $get): bool => filled($get('vendor_id'))),

                TextInput::make('description')
                    ->label(__('admin.fields.description'))
                    ->required()
                    ->maxLength(200)
                    ->helperText(__('admin.recurring_expenses.help.description')),

                Select::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => ExpenseCategory::options())
                    ->required()
                    ->native(false)
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.recurring_expenses.hints.category')),

                TextInput::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('EGP')
                    ->helperText(__('admin.recurring_expenses.help.amount')),

                Select::make('frequency')
                    ->label(__('admin.fields.frequency'))
                    ->options(fn (): array => collect(RecurringExpense::FREQUENCIES)
                        ->mapWithKeys(fn (string $f): array => [$f => __("admin.recurring_expenses.frequencies.{$f}")])
                        ->all())
                    ->required()
                    ->native(false),

                TextInput::make('payment_terms_days')
                    ->label(__('admin.recurring_expenses.fields.payment_terms_days'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->suffix(__('admin.recurring_expenses.days'))
                    // Only a payable has terms. An expense is money already gone.
                    ->visible(fn (Get $get): bool => filled($get('vendor_id')))
                    ->helperText(__('admin.recurring_expenses.help.payment_terms_days')),

                TextInput::make('day_of_month')
                    ->label(__('admin.recurring_expenses.fields.day_of_month'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->default(1)
                    ->required()
                    ->helperText(__('admin.recurring_expenses.help.day_of_month')),

                DatePicker::make('starts_on')
                    ->label(__('admin.fields.start_date'))
                    ->required()
                    ->native(false)
                    ->helperText(__('admin.recurring_expenses.help.starts_on')),

                DatePicker::make('ends_on')
                    ->label(__('admin.fields.end_date'))
                    ->native(false)
                    ->placeholder(__('admin.recurring_expenses.fields.no_end'))
                    ->helperText(__('admin.recurring_expenses.help.ends_on')),

                Select::make('tax_code')
                    ->label(__('admin.fields.tax_code'))
                    ->options(fn () => TaxCode::options(TaxCode::PURCHASES))
                    ->native(false)
                    ->placeholder(__('admin.charge_codes.tax_unclassified'))
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.recurring_expenses.hints.tax_code')),

                Toggle::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->default(true)
                    ->helperText(__('admin.recurring_expenses.help.is_active')),

                Textarea::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
