<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Schemas;

use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Support\Filament\BankAccountField;
use App\Support\Filament\EntitySelect;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DepositTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        // A cancelled deposit is a terminal record — read-only.
        $locked = fn (?DepositTransaction $record) => $record !== null && $record->status !== 'recorded';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.deposit_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.deposit_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    // Tenant + asset are derived from the lease in the model, so the picker must be
                    // property-scoped — which is now OptionDisplay's job, from Lease's own
                    // `#[PropertyOwned(via: 'unit')]`. What stays is narrowing to the SELECTED mall.
                    EntitySelect::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->required()
                        ->entity(Lease::class)
                        ->modifyOptionsQuery(fn ($query) => $query->when(
                            TenantScope::currentAssetId(),
                            fn ($q, $assetId) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)),
                        ))
                        ->disabled($locked),

                    // Which bank account this money moved through — optional, and null means the rail
                    // decides, exactly as before. Set it and the posting lands in THAT account's chart
                    // account, which is what lets a mall banking in two places reconcile either one.
                    BankAccountField::make()
                        ->disabled($locked),

                    Select::make('type')
                        ->label(__('admin.filters.type'))
                        ->options(fn () => __('admin.enums.deposit_type'))
                        ->required()
                        ->native(false)
                        // Live so the cutover toggle below appears/disappears with the type rather
                        // than only on reload — an opening flag left visible on a refund is the
                        // combination the model refuses.
                        ->live()
                        ->disabled($locked),

                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        // Derived from the column's own value set — see DepositTransaction::methodOptions().
                        // This form had the right two values by hand; the lease modal picked a
                        // different list and could not save at all.
                        ->options(fn () => DepositTransaction::methodOptions())
                        ->default('bank')
                        ->native(false)
                        ->required()
                        ->disabled($locked),

                    DatePicker::make('transaction_date')
                        ->label(__('admin.fields.transaction_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->disabled($locked),

                    // The cutover switch. Visible only on a RECEIPT, because that is the only
                    // movement that can predate this system: a refund or forfeit of an old deposit
                    // is our own cash moving and must post (the model refuses the combination).
                    Toggle::make('is_opening_balance')
                        ->label(__('admin.fields.is_opening_balance'))
                        ->helperText(__('admin.helpers.is_opening_deposit'))
                        ->visible(fn (Get $get) => $get('type') === 'receipt')
                        ->disabled($locked)
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),
        ]);
    }
}
