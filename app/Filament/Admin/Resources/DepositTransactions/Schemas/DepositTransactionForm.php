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

        // **The MONEY lock, and it is a different question from the one above.**
        //
        // `$locked` asks "has this row left the books", which freezes a CANCELLED deposit and leaves
        // a live one wide open. The model asks something else entirely and refuses in `saving`:
        //
        //   • a RECEIPT is fixed once the lease's pot `hasBeenDrawnOn()` — netted onto an invoice,
        //     refunded or forfeited — because editing it down after 80,000 was netted against
        //     arrears leaves `depositHeld()` negative and the receipt's GL entry re-derived while
        //     the application's `Dr Deposits Held` does not move;
        //   • ANY row is fixed once `finalAccountIsSettled()`, because a settled move-out is
        //     evidence: measured, a 100,000 refund already paid out could be edited to 10,000, the
        //     pot climbed back to 90,000 and a second refund against that phantom was accepted.
        //
        // So on a drawn-on receipt the form rendered every field enabled, the operator retyped the
        // amount, and the model answered with a `DomainException` toast on submit. The house rule is
        // the one `ExpenseForm` states beside its own `$moneyLocked`: the same predicate on both
        // layers, so the operator sees a disabled field and the reason instead of a refusal after
        // the fact — and a rule stated twice is stated once.
        //
        // The column sets are the model's, not a stricter guess: `bank_account_id`, `method`,
        // `is_opening_balance` and `notes` are in neither freeze, so they stay on `$locked` alone.
        // `status` is frozen by the receipt guard and has no field here — `cancel_deposit` on this
        // page is the escape, which is why the settled guard deliberately leaves `status` out.
        // Memoised per record: both predicates are queries, four fields ask, and Filament evaluates
        // a `disabled()` closure on every render pass — so without this one edit page costs a dozen
        // round trips to answer one question. Keyed by id (with a `new` sentinel) rather than held
        // in a static, because the schema is rebuilt per request and a static would outlive it.
        $drawnOrSettled = [];
        $moneyLocked = function (?DepositTransaction $record) use (&$drawnOrSettled): bool {
            if ($record === null) {
                return false;
            }

            $key = $record->getKey() ?? 'new';

            return $drawnOrSettled[$key] ??= (
                ($record->type === 'receipt' && $record->hasBeenDrawnOn())
                || $record->finalAccountIsSettled()
            );
        };

        /** Either reason to freeze a money field: it left the books, or something depends on it. */
        $frozen = fn (?DepositTransaction $record) => $locked($record) || $moneyLocked($record);

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
                        ->disabled($frozen),

                    // Which bank account this money moved through. `for()` takes the document class
                    // because the document declares BOTH the purpose its money belongs to and the
                    // column naming its rail — so the picker defaults to the same account
                    // `RecordsBankAccount` would have filled in, and requires one on exactly the
                    // rails the catalogue says carry bank money.
                    BankAccountField::for(DepositTransaction::class)
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
                        ->disabled($frozen),

                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        // Derived from the column's own value set — see DepositTransaction::methodOptions().
                        // This form had the right two values by hand; the lease modal picked a
                        // different list and could not save at all.
                        ->options(fn () => DepositTransaction::methodOptions())
                        // The default comes from the same list as the options (SW-116).
                        ->default(fn () => DepositTransaction::defaultMethod())
                        ->native(false)
                        ->required()
                        ->disabled($locked)
                        // `->live()` so the bank-account field beside it picks up its requirement as soon as the
                        // rail changes. The refusal itself does not depend on this — `required()` is evaluated at
                        // validation with the submitted rail in state — this only decides how soon the asterisk
                        // and the helper sentence catch up.
                        ->live(),

                    DatePicker::make('transaction_date')
                        ->label(__('admin.fields.transaction_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($frozen),

                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->disabled($frozen),

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
