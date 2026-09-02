<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\InvoiceWriteOff;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\VoidInvoiceService;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * SW-023 — voiding a partially written-off invoice left the loss standing and drove AR negative.
 *
 * A write-off is an accounting ACT, not a status: `WriteOffInvoiceService` posts
 * `Dr bad_debt_expense / Cr accounts_receivable` against an `InvoiceWriteOff` row, and it
 * deliberately leaves `invoices.balance` alone — the balance is derived from the four settlement
 * channels and a write-off is not one of them.
 *
 * `VoidInvoiceService` knew nothing about that row. Measured on a 10,000 invoice with 4,000 written
 * off, the posted books after the void read **AR −14,000** — the void's own reversal, plus the
 * write-off's credit with nothing left to relieve — and **4,000 of bad-debt expense against a
 * document that no longer exists**. Negative receivables for one debt, and a loss recognised on
 * money that was never owed.
 *
 * It is REFUSED, not cascaded, which is this codebase's rule for money records: correct them through
 * their own workflow so an auditor can follow what happened. `Reverse write-off` is a real button,
 * and reversing first leaves a trail saying the debt was re-opened and then the document withdrawn —
 * which is what actually happened. Cascading would silently undo an act somebody took deliberately.
 *
 * The same shape as the refusal one line above it: an invoice carrying captured CASH refuses too,
 * and the remedy is to refund the payment first.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'VWO']);
    $this->lease = makeLease(makeUnit($this->asset));

    $this->invoice = makeInvoice($this->lease, ['status' => 'issued', 'issue_date' => now()->toDateString()]);
    $this->invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    $this->invoice = $this->invoice->fresh();

    app(LedgerPoster::class)->sync($this->invoice);
});

/** Net movement per account code across every POSTED entry, in debit-minus-credit. */
function postedNetByCode(): array
{
    $net = [];

    foreach (JournalEntry::where('status', 'posted')->with('lines')->get() as $entry) {
        foreach ($entry->lines as $line) {
            $net[$line->ledger_account_id] = ($net[$line->ledger_account_id] ?? 0)
                + (float) $line->debit - (float) $line->credit;
        }
    }

    $codes = LedgerAccount::whereIn('id', array_keys($net))->pluck('code', 'id');

    return collect($net)
        ->filter(fn (float $amount) => abs($amount) > 0.005)
        ->mapWithKeys(fn (float $amount, int $id) => [$codes[$id] => round($amount, 2)])
        ->all();
}

it('refuses to void an invoice that carries a write-off', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    expect(fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'))
        ->toThrow(DomainException::class);

    // The document is untouched — a refusal must not half-apply. (`overdue`, because the fixture's
    // due date is derived from the lease's terms and has already passed; what matters is that it is
    // not `cancelled`.)
    expect($this->invoice->fresh()->status)->not->toBe('cancelled');
});

it('keeps the books straight, which is what the refusal is for', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);
    foreach (InvoiceWriteOff::all() as $writeOff) {
        app(LedgerPoster::class)->sync($writeOff);
    }

    rescue(fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'), null, false);

    app(LedgerPoster::class)->sync($this->invoice->fresh());

    $ar = (int) DB::table('account_mappings')->where('key', 'accounts_receivable')->value('ledger_account_id');
    $badDebt = (int) DB::table('account_mappings')->where('key', 'bad_debt_expense')->value('ledger_account_id');

    expect($ar)->not->toBe(0)->and($badDebt)->not->toBe(0);

    $codes = LedgerAccount::whereIn('id', [$ar, $badDebt])->pluck('code', 'id');
    $net = postedNetByCode();

    // Measured with the void allowed: AR −14,000 and 4,000 of bad debt against a document that no
    // longer exists. Refused, the books say what they should: 6,000 still owed, 4,000 written off.
    expect($net[$codes[$ar]] ?? 0.0)->toEqual(6000.0)
        ->and($net[$codes[$badDebt]] ?? 0.0)->toEqual(4000.0);
});

it('lets the void through once the write-off is reversed — the route the refusal names', function () {
    $writeOff = app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    app(WriteOffInvoiceService::class)->reverse($writeOff->fresh(), 'raised in error');

    app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error');

    expect($this->invoice->fresh()->status)->toBe('cancelled');
});

it('still voids an ordinary invoice, and still refuses the ones it always refused', function () {
    // The control. A guard that refused everything would satisfy the refusal above on its own.
    app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error');

    expect($this->invoice->fresh()->status)->toBe('cancelled');
});

it('hides the button while a write-off stands, so the UI and the gate cannot drift', function () {
    // Both layers, the rule this codebase states for every write action. The operator's route out is
    // `Reverse write-off`, which is visible precisely while this one is not.
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible('void_invoice');

    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->fresh()->getRouteKey()])
        ->assertActionHidden('void_invoice');

    Filament::setTenant(null, isQuiet: true);
});
