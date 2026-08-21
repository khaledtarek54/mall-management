<?php

namespace App\Filament\Admin\Resources\ExpenseCategories\Schemas;

use App\Models\LedgerAccount;
use App\Support\CostNature;
use App\Support\Filament\EntitySelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class ExpenseCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(40)
                // Immutable once saved: every bill, expense and custody line stores the STRING, not
                // a foreign key, so changing it orphans them.
                ->disabledOn('edit')
                ->helperText(__('admin.expense_categories.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record) => Rule::unique('expense_categories', 'code')->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(64),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(64),

            EntitySelect::make('ledger_account_id')
                ->label(__('admin.fields.ledger_account'))
                ->entity(LedgerAccount::class)
                ->preload()
                // Postable, active EXPENSE leaves. A cost has to land in a P&L account, and a
                // summary account cannot carry a balance.
                ->modifyOptionsQuery(fn ($query) => $query
                    ->where('is_postable', true)
                    ->where('is_active', true)
                    ->where('type', 'expense'))
                ->helperText(__('admin.expense_categories.help.ledger_account'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.expense_category_ledger_account')),

            Select::make('cost_nature')
                ->label(__('admin.fields.cost_nature'))
                ->options(fn () => collect(CostNature::NATURES)
                    ->mapWithKeys(fn (string $n) => [$n => __("admin.enums.cost_nature.{$n}")])->all())
                ->default(CostNature::VARIABLE)
                ->required()
                ->native(false)
                ->helperText(__('admin.expense_categories.help.cost_nature')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.expense_categories.help.is_active')),

            Textarea::make('notes')->label(__('admin.fields.notes'))->rows(2)->columnSpanFull(),
        ]);
    }
}
