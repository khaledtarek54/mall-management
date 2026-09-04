<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\InvoiceWriteOff;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\LeaseTerminationService;
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
 * off, the books after the void read **AR −4,000** — the invoice's own debit reversed, with the
 * write-off's credit left standing against nothing — and **4,000 of bad-debt expense against a
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

/**
 * Net movement per account code across every REPORTABLE entry, in debit-minus-credit.
 *
 * `JournalEntry::REPORTABLE_STATUSES` is `['posted', 'void']`, and that is what every financial read
 * uses — `LedgerReportService`, `VatReturnService`, the CAM sync, the bank reconciliation.
 * `LedgerPoster` says so in writing: *"Contrast REPORTABLE_STATUSES, which governs SUMS."* Voiding
 * does not erase an entry; it posts a sign-flipped reversal and marks the original `void`, so both
 * halves must be counted or the reversal is read without the thing it reverses.
 *
 * The first version of this helper filtered `status = 'posted'` alone and reported AR at −14,000 —
 * and the tell that the CONVENTION was wrong rather than the books is that the same sum showed a
 * **debit balance on a revenue account**, which cannot happen. The real figure is −4,000, which is
 * still the defect, just not the one the number claimed.
 */
function reportableNetByCode(): array
{
    $net = [];

    foreach (JournalEntry::whereIn('status', JournalEntry::REPORTABLE_STATUSES)->with('lines')->get() as $entry) {
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

    // The MESSAGE, not merely the class: this path throws three different `DomainException`s — an
    // ETA-filed invoice, captured cash, and this — and a bare class assertion cannot tell them
    // apart, so it would pass on a refusal for the wrong reason.
    expect(fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'))
        ->toThrow(DomainException::class, __('admin.refusals.invoice_void_has_write_off', [
            'number' => $this->invoice->number,
        ]));

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

    // Only the refusal is swallowed. `rescue()` catches `Throwable`, so a TypeError inside `void()`
    // would have left this green.
    try {
        app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error');
    } catch (DomainException) {
        // expected — the point of the case is what the books read afterwards
    }

    app(LedgerPoster::class)->sync($this->invoice->fresh());

    $ar = (int) DB::table('account_mappings')->where('key', 'accounts_receivable')->value('ledger_account_id');
    $badDebt = (int) DB::table('account_mappings')->where('key', 'bad_debt_expense')->value('ledger_account_id');

    expect($ar)->not->toBe(0)->and($badDebt)->not->toBe(0);

    $codes = LedgerAccount::whereIn('id', [$ar, $badDebt])->pluck('code', 'id');
    $net = reportableNetByCode();

    // Measured with the void allowed: AR **−4,000** and 4,000 of bad debt against a document that no
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

it('still voids an ordinary invoice', function () {
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

it('does not let LEASE TERMINATION cancel a written-off invoice either', function () {
    // **The path that actually produces this**, and the service guard could not see it.
    // `LeaseTerminationService` cancels open invoices with a direct
    // `update(['status' => 'cancelled', 'balance' => 0])` — never through `VoidInvoiceService` — and
    // its filter is `status in (draft, issued, partially_paid, overdue) AND balance > 0 AND
    // paid_amount = 0`. A partially written-off invoice matches every clause **precisely because** a
    // write-off leaves `balance` standing and is not a settlement channel, so `paid_amount` stays 0.
    // And it is the default: the `cancel_open_invoices` tick is on.
    //
    // Excluded at the SELECTION, the way the query already excludes an ETA-filed invoice — the model
    // guard below is the backstop, but this loop has no per-row catch, so a refusal there would
    // abort the whole termination and leave the lease un-terminatable.
    $future = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'period_start' => now()->addMonths(2)->startOfMonth()->toDateString(),
        'period_end' => now()->addMonths(2)->endOfMonth()->toDateString(),
    ]);
    $future->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    app(WriteOffInvoiceService::class)->write($future->fresh(), ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    app(LeaseTerminationService::class)->terminate($this->lease->fresh(), [
        'termination_date' => now()->toDateString(),
        'reason' => 'Tenant vacated.',
        'cancel_open_invoices' => true,
    ]);

    // Measured before: `cancelled`, with the write-off's credit left standing against nothing.
    expect($future->fresh()->status)->not->toBe('cancelled');
});

it('refuses a direct cancel on ANY path, which is the backstop', function () {
    // `Invoice::updating` — the same place, and the same reasoning, as the captured-cash guard that
    // already says in writing it must hold "on EVERY path, not just VoidInvoiceService".
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    expect(fn () => $this->invoice->fresh()->update(['status' => 'cancelled', 'balance' => 0]))
        ->toThrow(DomainException::class, __('admin.refusals.invoice_void_has_write_off', [
            'number' => $this->invoice->number,
        ]));

    // …and the control: an ordinary invoice still cancels through the same door.
    $clean = makeInvoice($this->lease, ['status' => 'issued']);

    $clean->update(['status' => 'cancelled']);

    expect($clean->fresh()->status)->toBe('cancelled');
});
