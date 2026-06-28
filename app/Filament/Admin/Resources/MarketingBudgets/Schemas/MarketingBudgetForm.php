<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketingBudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.resources.marketing_budget.singular'))
                ->columns(2)
                ->components([
                    // Identity fields are auto-provisioned (one budget per property
                    // per year) — shown read-only, never editable.
                    Select::make('asset_id')
                        ->label(__('admin.tables.marketing_budget.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->disabled(),
                    TextInput::make('period_year')
                        ->label(__('admin.tables.marketing_budget.year'))
                        ->numeric()
                        ->disabled(),
                    Select::make('status')
                        ->label(__('admin.tables.marketing_budget.status'))
                        ->options(['open' => 'Open', 'closed' => 'Closed'])
                        ->default('open')
                        ->required()
                        ->native(false),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            // The fund: derived from billed levies (income) − spends (expenses).
            // Read-only — accrued is auto-derived from invoices, never hand-set.
            Section::make(__('admin.tables.marketing_budget.fund'))
                ->visible(fn ($record) => $record !== null)
                ->columns(3)
                ->components([
                    Placeholder::make('accrued_display')
                        ->label(__('admin.tables.marketing_budget.accrued'))
                        ->content(fn ($record) => number_format((float) $record->accrued_amount, 2).' EGP'),
                    Placeholder::make('spent_display')
                        ->label(__('admin.tables.marketing_budget.spent'))
                        ->content(fn ($record) => number_format((float) $record->spent_amount, 2).' EGP'),
                    Placeholder::make('balance_display')
                        ->label(__('admin.tables.marketing_budget.balance'))
                        ->content(fn ($record) => number_format((float) $record->balance(), 2).' EGP'),
                ]),
        ]);
    }
}
