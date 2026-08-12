<?php

namespace App\Filament\Admin\Resources\BankStatements\Schemas;

use App\Models\BankAccount;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                    Select::make('bank_account_id')
                        ->label(__('admin.resources.bank_account.singular'))
                        // Scoped to the accounts this user can see — the statement inherits its
                        // property from the account, so an unscoped picker would be the leak.
                        ->options(fn () => BankAccount::query()
                            ->when(
                                TenantScope::visibleAssetIds() !== null,
                                fn ($q) => $q->whereIn('asset_id', TenantScope::visibleAssetIds() ?? []),
                            )
                            ->when(TenantScope::currentAssetId(), fn ($q, $id) => $q->where('asset_id', $id))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $a) => [$a->id => $a->displayName()])
                            ->all())
                        ->required()
                        ->native(false)
                        ->searchable(),

                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->native(false),

                    DatePicker::make('period_end')
                        ->label(__('admin.fields.period_end'))
                        ->required()
                        ->native(false)
                        ->afterOrEqual('period_start'),

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
