<?php

namespace App\Filament\Admin\Resources\BankAccounts\Schemas;

use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.bank_account'))
                ->description(__('admin.helpers.bank_account_section'))
                ->columns(2)
                ->components([
                    PropertyField::make()
                        ->searchable(),

                    TextInput::make('name')
                        ->label(__('admin.fields.bank_account_name'))
                        ->required()
                        ->maxLength(120)
                        ->placeholder(__('admin.placeholders.bank_account_name')),

                    TextInput::make('bank_name')
                        ->label(__('admin.fields.bank_name'))
                        ->maxLength(120),

                    TextInput::make('account_number')
                        ->label(__('admin.fields.bank_account_number'))
                        ->maxLength(64)
                        ->helperText(__('admin.helpers.bank_account_number')),

                    TextInput::make('iban')
                        ->label(__('admin.fields.iban'))
                        ->maxLength(64),

                    // What kind of money this account holds — Yardi's own split of a property's
                    // cash accounts, and what lets a money document default to the right one
                    // without asking. A deposit receipt and a rent receipt are both "money in" on
                    // the same rail and belong in different places.
                    Select::make('purpose')
                        ->label(__('admin.fields.purpose'))
                        ->options(fn () => __('admin.enums.bank_account_purpose'))
                        ->default(BankAccount::PURPOSE_OPERATING)
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.bank_account_purpose')),

                    // The half that makes requiring an account on a money form reasonable rather
                    // than a chore: with this set, a new document arrives with its bank already
                    // filled and the operator confirms instead of choosing. One per property per
                    // purpose — flagging this one demotes the previous holder (BankAccount::booted).
                    Toggle::make('is_default')
                        ->label(__('admin.fields.is_default'))
                        ->default(false)
                        ->helperText(__('admin.helpers.bank_account_is_default')),

                    EntitySelect::make('ledger_account_id')
                        ->label(__('admin.fields.ledger_account'))
                        ->entity(LedgerAccount::class)
                        // Postable leaves only — a summary account cannot carry a balance, and
                        // offering one here would produce a bank that can never tie out.
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->where('is_postable', true)
                            ->where('is_active', true))
                        ->helperText(__('admin.helpers.bank_ledger_account'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.bank_ledger_account')),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true),

                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
