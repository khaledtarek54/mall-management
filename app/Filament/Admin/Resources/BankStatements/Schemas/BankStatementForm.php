<?php

namespace App\Filament\Admin\Resources\BankStatements\Schemas;

use App\Models\BankAccount;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\TenureRange;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BankStatementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.bank_statement'))
                ->description(__('admin.helpers.bank_statement_section'))
                ->columns(2)
                ->components([
                    // The visible-properties half of this scope is OptionDisplay's (BankAccount is
                    // `#[PropertyOwned]`); the SELECTED-property half stays, because narrowing to the
                    // mall the operator is working in is this form's choice, not isolation.
                    EntitySelect::make('bank_account_id')
                        ->label(__('admin.resources.bank_account.singular'))
                        ->entity(BankAccount::class)
                        ->modifyOptionsQuery(fn ($query) => $query->when(
                            TenantScope::currentAssetId(),
                            fn ($q, $id) => $q->where('asset_id', $id),
                        ))
                        ->required(),

                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->native(false),

                    DatePicker::make('period_end')
                        ->label(__('admin.fields.period_end'))
                        ->required()
                        ->native(false)
                        ->minDate(TenureRange::endsOnOrAfter('period_start')),

                    TextInput::make('opening_balance')
                        ->label(__('admin.fields.opening_balance'))
                        ->numeric()
                        ->default(0)
                        ->prefix('EGP'),

                    TextInput::make('closing_balance')
                        ->label(__('admin.fields.closing_balance'))
                        ->numeric()
                        ->default(0)
                        ->prefix('EGP')
                        ->helperText(__('admin.helpers.closing_balance'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.closing_balance')),

                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
