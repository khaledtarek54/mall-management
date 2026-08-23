<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Schemas;

use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\TaxCode;
use App\Support\Filament\PropertyField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
