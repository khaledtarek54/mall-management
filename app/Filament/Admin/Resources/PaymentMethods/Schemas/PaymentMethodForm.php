<?php

namespace App\Filament\Admin\Resources\PaymentMethods\Schemas;

use App\Models\LedgerAccount;
use App\Support\Filament\EntitySelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(32)
                // The value the money documents store. Immutable once saved: changing it would
                // orphan every payment, bill payment, deposit and expense already naming it —
                // they hold the string, not a foreign key.
                ->disabledOn('edit')
                ->helperText(__('admin.payment_methods.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record) => Rule::unique('payment_methods', 'code')->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(64),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(64),

            EntitySelect::make('ledger_account_id')
                ->label(__('admin.fields.ledger_account'))
                ->entity(LedgerAccount::class)
                // A record picker, so it goes through EntitySelect — a bare Select searches one raw
                // column and folds neither side (CLAUDE.md). The chart is browsed, not typed, so it
                // preloads.
                ->preload()
                // Postable, active ASSET leaves only — the same filter `BankAccountForm` applies,
                // and this was the one of three siblings without it. A summary account cannot carry
                // a balance, an inactive one should not take new money, and money received has to
                // land in an asset: offering any of those produces a rail that can never tie out.
                ->modifyOptionsQuery(fn ($query) => $query
                    ->where('is_postable', true)
                    ->where('is_active', true)
                    ->where('type', 'asset'))
                // Null is the normal state and the safe one; the helper says what leaving it blank
                // DOES, because that is what changes what the operator types.
                ->helperText(__('admin.payment_methods.help.ledger_account'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.payment_method_ledger_account')),

            Toggle::make('for_inbound')
                ->label(__('admin.fields.for_inbound'))
                ->default(true)
                ->helperText(__('admin.payment_methods.help.for_inbound')),

            Toggle::make('for_outbound')
                ->label(__('admin.fields.for_outbound'))
                ->default(true)
                ->helperText(__('admin.payment_methods.help.for_outbound')),

            TextInput::make('settlement_days')
                ->label(__('admin.fields.settlement_days'))
                ->numeric()->minValue(0)->maxValue(365)->default(0)
                ->helperText(__('admin.payment_methods.help.settlement_days')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.payment_methods.help.is_active')),

            Textarea::make('notes')->label(__('admin.fields.notes'))->rows(2)->columnSpanFull(),
        ]);
    }
}
