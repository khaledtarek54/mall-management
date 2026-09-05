<?php

/**
 * SW-016 — a filter called "Overdue Only" returned every unpaid invoice, on the one surface where
 * the reader is the person being asked for the money.
 *
 * Three answers to one question lived on the tenant portal at once:
 *
 *   - the dashboard counted `where('status', 'overdue')` — a STAMP the nightly sweep writes, which
 *     lags a day and which a `partially_paid` invoice can never carry;
 *   - the invoice list's "Overdue Only" filter ran `whereCollectable()`, i.e. everything unpaid;
 *   - the `due_date` column on that same list coloured every past-due row red.
 *
 * Measured on the QA baseline (`mall_management_qa`): 4 invoices carry the status, 11 are genuinely
 * past due and still owed, and 108 merely have something left on them. So the tenant read "4
 * overdue", saw 11 red rows, and got 108 when they clicked the word Overdue — and the HEADLINE
 * stat, Outstanding balance, deep-linked into that same mislabelled filter, so clicking an
 * outstanding figure landed on a list captioned Overdue.
 *
 * The fix names the definition that already existed six times over — `Invoice::scopeOverdue()` —
 * and routes both halves of the portal through it, with the unpaid filter finally wearing the word
 * its own key has always used. Every refusal below is paired with a control that must SUCCEED: a
 * scope that returned nothing would satisfy the narrowing assertions on its own.
 */

use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Portal\Widgets\AccountBalance;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);

    // Past due, and the nightly `billing:scan-overdue-invoices` has not reached it — the status
    // still reads `issued`. This is the row a status-only count cannot see, and on the demo books
    // it is most of them.
    $this->pastDueIssued = makeInvoice($this->lease, [
        'issue_date' => now()->subDays(45)->toDateString(),
        'due_date' => now()->subDays(15)->toDateString(),
        'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000,
    ]);

    // Part-settled and past due. `partially_paid` can NEVER become `status = 'overdue'`, so the old
    // count could not have seen this one however long it waited.
    $this->pastDuePartPaid = makeInvoice($this->lease, [
        'issue_date' => now()->subDays(60)->toDateString(),
        'due_date' => now()->subDays(30)->toDateString(),
        'status' => 'partially_paid',
        'subtotal' => 4000, 'vat_amount' => 0, 'total' => 4000,
        'paid_amount' => 1000, 'balance' => 3000,
    ]);

    // Already stamped by the sweep — the only one the old count could see.
    $this->flaggedOverdue = makeInvoice($this->lease, [
        'issue_date' => now()->subDays(90)->toDateString(),
        'due_date' => now()->subDays(60)->toDateString(),
        'status' => 'overdue',
        'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000,
        'paid_amount' => 0, 'balance' => 7000,
    ]);

    // Unpaid and NOT yet due. The discriminator: it belongs on the outstanding list and must never
    // appear under the word Overdue.
    $this->notYetDue = makeInvoice($this->lease, [
        'issue_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(20)->toDateString(),
        'status' => 'issued',
        'subtotal' => 9000, 'vat_amount' => 0, 'total' => 9000,
        'paid_amount' => 0, 'balance' => 9000,
    ]);

    // Settled — on neither list, so a filter that had stopped narrowing would be caught.
    $this->settled = makeInvoice($this->lease, [
        'issue_date' => now()->subDays(120)->toDateString(),
        'due_date' => now()->subDays(90)->toDateString(),
        'status' => 'paid',
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000,
        'paid_amount' => 2000, 'balance' => 0,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('shows only what is actually past due under Overdue', function () {
    $rows = tableRows(Livewire::test(ListInvoices::class)->filterTable('overdue_only'));

    // The control first — the three that really are late, including the two no status stamp names.
    expect($rows->pluck('id')->all())->toEqualCanonicalizing([
        $this->pastDueIssued->id,
        $this->pastDuePartPaid->id,
        $this->flaggedOverdue->id,
    ]);

    // …and the narrowing: an invoice that is unpaid but not yet due is not overdue, which is the
    // whole of what the word means.
    expect($rows->pluck('id')->all())
        ->not->toContain($this->notYetDue->id)
        ->not->toContain($this->settled->id);
});

it('still shows everything owed under Unpaid, which is what the outstanding figure counts', function () {
    $rows = tableRows(Livewire::test(ListInvoices::class)->filterTable('unpaid_only'));

    // FOUR, not three: the not-yet-due invoice belongs here. A filter that had simply become the
    // overdue one would pass every assertion in the case above and be wrong on the stat that links
    // to it.
    expect($rows->pluck('id')->all())->toEqualCanonicalizing([
        $this->pastDueIssued->id,
        $this->pastDuePartPaid->id,
        $this->flaggedOverdue->id,
        $this->notYetDue->id,
    ]);

    // It is the set `Tenant::outstandingBalance()` sums, which is the figure that deep-links here.
    expect(round($this->tenant->outstandingBalance(), 2))->toBe(24000.0)
        ->and(round((float) $rows->sum(fn (Invoice $i) => $i->collectableBalance()), 2))->toBe(24000.0);
});

it('the word on the filter is the query behind it', function () {
    $table = Livewire::test(ListInvoices::class)->instance()->getTable();

    // The defect was entirely in this one string: the key said unpaid, the query said unpaid, and
    // the only thing the tenant could read said overdue.
    expect($table->getFilter('unpaid_only')?->getLabel())->toBe(__('admin.filters.unpaid_only'))
        ->and($table->getFilter('unpaid_only')?->getLabel())->not->toBe(__('admin.filters.overdue_only'))
        ->and($table->getFilter('overdue_only')?->getLabel())->toBe(__('admin.filters.overdue_only'));
});

it('the dashboard overdue stat equals the rows its own link lands on', function () {
    $widget = Livewire::test(AccountBalance::class)->instance();

    $stats = (new ReflectionMethod(AccountBalance::class, 'getStats'))->invoke($widget);

    $overdue = collect($stats)->first(
        fn ($stat) => $stat->getLabel() === __('admin.widgets.account_balance.overdue_invoices'),
    );

    expect($overdue)->not->toBeNull();

    $rows = tableRows(Livewire::test(ListInvoices::class)->filterTable('overdue_only'));

    // The house rule: a figure a tenant can click must equal the rows it lands on. The stat read
    // ONE here (only `flaggedOverdue` carries the status) against three genuinely late invoices.
    expect($rows)->toHaveCount(3)
        ->and((string) $overdue->getValue())->toBe('3');

    // …and it must link at the filter, not at a status the sweep may not have written yet.
    expect($overdue->getUrl())->toContain('overdue_only');
});

it('agrees with the figure the mobile app is given for the same tenant', function () {
    // The portal and /api/v1 are one surface with two renderers. `/me/balance` has always read the
    // correct definition; the portal is what was brought to it, so the two now describe one set.
    $overdueByScope = $this->tenant->invoices()->overdue()->with('writeOffs')->get();

    $response = $this->withHeaders(apiHeaders($this->tenant))->getJson('/api/v1/me/balance');

    $response->assertOk();

    expect($overdueByScope)->toHaveCount(3)
        ->and(round((float) $response->json('data.overdue'), 2))
        ->toBe(round((float) $overdueByScope->sum(fn (Invoice $i) => $i->collectableBalance()), 2))
        ->and(round((float) $response->json('data.overdue'), 2))->toBe(15000.0);
});
