<?php

namespace App\Filament\Admin\Resources\BankAccounts\Schemas;

use App\Models\LedgerAccount;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.bank_account'))
                ->description(__('admin.helpers.bank_account_section'))
                ->columns(2)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        // Property-scoped picker: never the raw Asset list, or a restricted user
                        // could file an account against a mall they cannot see.
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->required()
                        ->native(false)
                        ->searchable(),

                    TextInput::make('name')
                        ->label(__('admin.fields.bank_account_name'))
                        ->required()
                        ->maxLength(120)
                        ->placeholder('CIB — current'),

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

                    Select::make('ledger_account_id')
                        ->label(__('admin.fields.ledger_account'))
                        // Postable leaves only — a summary account cannot carry a balance, and
                        // offering one here would produce a bank that can never tie out.
                        ->options(fn () => LedgerAccount::query()
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->code.' — '.$a->displayName()])
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.helpers.bank_ledger_account')),

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
