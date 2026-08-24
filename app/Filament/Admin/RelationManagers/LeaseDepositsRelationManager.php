<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Actions\LeaseActions;
use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\DepositTransaction;
use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The security deposit held against this lease, and what has happened to it.
 *
 * "Have they paid the deposit, and how much of it is still ours?" is a lease question asked at
 * signing, at renewal and again at move-out — and it was answered in the deposit register, filtered
 * by hand. The lease carried `security_deposit` (what was AGREED) and `security_deposit_received`
 * (a yes/no), neither of which tells you what was actually received, refunded, forfeited or netted
 * against arrears.
 *
 * Read-only, and the movement is recorded from the lease's own **Record deposit movement** action
 * rather than from a button on this table — one place to act, beside every other act on a tenancy.
 *
 * This used to say a create button here "would be a second way to move money, thinner than the
 * first", and that reasoning was wrong on the facts (corrected 2026-08-18): every guard is on the
 * MODEL — `GuardsPostingDate`, `AllocatesDocumentNumber`, the ValueSets listener, the GL registry —
 * so any surface that creates a `DepositTransaction` inherits all of them. The register remains the
 * portfolio view: what the property holds in total, which is a balance-sheet question and not a
 * lease one.
 */
class LeaseDepositsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'deposits';

    /** Memoises {@see self::held()} for the life of one render. */
    protected ?float $heldCache = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.deposit_transactions');
    }

    public function table(Table $table): Table
    {
        return $table
            // The acts that change THIS tab's data, composed from the registry rather than
            // re-declared: an operator reading "no deposit movements" is one click from recording
            // one, instead of scrolling to a header dropdown to find out how.
            ->headerActions(LeaseActions::forOwner($this->lease(), ['billDeposit', 'recordDeposit']))
            // What this tenancy's deposit actually stands at, stated ABOVE the movements — because
            // the movements are no longer the whole of it. A deposit billed on an invoice and paid
            // by the tenant is held and owed back, and writes no row here at all; without this line
            // an empty table reads as "they never paid a deposit" on a lease holding 144,000.
            ->description(fn (): string => $this->depositSummary())
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.fields.deposit_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('transaction_date')
                    ->label(__('admin.fields.transaction_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                // Receipt / refund / forfeit — the movement, not a running total.
                // A single "balance" column here would be a second truth about the same money.
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.deposit_type.{$state}"))
                    ->color(fn (string $state) => $state === 'receipt' ? 'success' : 'gray'),

                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP'),

                TextColumn::make('method')
                    ->label(__('admin.fields.method'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.deposit_transaction.{$state}")),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DepositTransaction $record): string => DepositTransactionResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (DepositTransaction $record): bool => DepositTransactionResource::canEdit($record)),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn () => $this->held() > 0
                ? __('admin.lease_deposits.empty_but_held_heading')
                : __('admin.lease_deposits.empty_heading'))
            // Two different situations that looked identical: nothing paid, versus paid through
            // the billing road, which leaves this table empty and the money very much held.
            ->emptyStateDescription(fn () => $this->held() > 0
                ? __('admin.lease_deposits.empty_but_held_description', [
                    'held' => 'EGP '.number_format($this->held(), 2),
                ])
                : __('admin.lease_deposits.empty_description'));
    }

    /** `getOwnerRecord()` returns the base `Model`; narrowed once so the registry call type-checks. */
    /**
     * What this tenancy holds, memoised for one render.
     *
     * `depositHeld()` runs an `InvoiceItemSettlement` pass over every deposit invoice on the lease,
     * and the table asks for it from three callbacks (the description and both empty-state
     * closures). Without this the same subtraction is recomputed on each.
     */
    protected function held(): float
    {
        return $this->heldCache ??= $this->lease()->depositHeld();
    }

    /**
     * Agreed · held · of which billed and settled · still owed — one line.
     *
     * Reads `Lease::depositHeld()`, the one definition, rather than summing this table: the table
     * is one of the two roads a deposit arrives by and summing it would restate the bug.
     */
    protected function depositSummary(): string
    {
        $lease = $this->lease();
        $money = fn (float $v) => 'EGP '.number_format($v, 2);

        $parts = [
            __('admin.deposits.summary_agreed', ['amount' => $money((float) ($lease->security_deposit ?? 0))]),
            __('admin.deposits.summary_held', ['amount' => $money($this->held())]),
        ];

        if (($billed = $lease->settledDepositBillings()) > 0) {
            $parts[] = __('admin.deposits.summary_billed', ['amount' => $money($billed)]);
        }

        if (($short = $lease->depositShortfall()) > 0) {
            $parts[] = __('admin.deposits.summary_shortfall', ['amount' => $money($short)]);
        }

        return implode(' · ', $parts);
    }

    protected function lease(): Lease
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();

        return $lease;
    }
}
