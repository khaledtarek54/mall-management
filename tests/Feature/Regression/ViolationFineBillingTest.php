<?php

use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Violation;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\BillViolationFineService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module 31 completion — violation fine BILLING. `fine_amount` recorded the money assessed but
 * nothing turned it into AR (the doc's §3 anticipated this: "if a future slice bills the fine, it must
 * go through the normal AR path"). These pin the billing path: a VAT-EXEMPT `violation_fine` invoice
 * → misc_income in the GL, idempotent, and — critically — that a fine dated to the violation month
 * does NOT make the monthly run think that month is already billed (which would skip base rent).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    $this->svc = app(BillViolationFineService::class);
});

function finedViolation(float $fine = 1000, bool $withLease = true, ?string $date = '2026-03-15', string $category = 'safety'): Violation
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    if ($withLease) {
        makeLease($unit, $tenant, ['status' => 'active', 'commencement_date' => '2026-01-01']);
    }

    return Violation::create([
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
        'category' => $category,
        'description' => 'Blocked fire exit',
        'fine_amount' => $fine,
        'violation_date' => $date,
        'status' => 'open',
    ]);
}

it('bills a violation fine as a VAT-exempt invoice and stamps the violation', function () {
    $violation = finedViolation(fine: 1000);

    $invoice = $this->svc->bill($violation);

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)     // a fine is a penalty — out of VAT scope
        ->and((float) $invoice->total)->toBe(1000.0)
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01') // the violation month
        ->and($invoice->items()->where('type', 'violation_fine')->exists())->toBeTrue()
        ->and($violation->fresh()->billed_invoice_id)->toBe($invoice->id)
        ->and($violation->fresh()->isBilled())->toBeTrue();
});

it('is idempotent — billing the same fine twice does not double-charge', function () {
    $violation = finedViolation();

    $first = $this->svc->bill($violation);
    $second = $this->svc->bill($violation->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::whereHas('items', fn ($q) => $q->where('type', 'violation_fine'))->count())->toBe(1);
});

it('refuses to bill when there is no fine, and when the tenant has no active lease in the property', function () {
    expect(fn () => $this->svc->bill(finedViolation(fine: 0)))->toThrow(DomainException::class);
    expect(fn () => $this->svc->bill(finedViolation(withLease: false)))->toThrow(DomainException::class);
});

it('posts the fine to misc_income and ties AR out through the real sweep', function () {
    $this->svc->bill(finedViolation(fine: 1000));

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $credited = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '42101001'))->sum('credit');

    expect($credited)->toBe(1000.0)                                                  // Miscellaneous Income
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue()
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('a violation fine invoice does NOT suppress that month\'s base rent in the monthly run', function () {
    // The trap: the fine invoice is dated to the violation month, which overlaps the monthly run's
    // already-billed probe. Without excluding `violation_fine` there, base rent would silently vanish.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant, ['status' => 'active', 'commencement_date' => '2026-01-01']);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    $this->svc->bill(Violation::create([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'category' => 'safety',
        'description' => 'Blocked exit', 'fine_amount' => 1000, 'violation_date' => '2026-03-15', 'status' => 'open',
    ]));

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $rent = Invoice::whereHas('items', fn ($q) => $q->where('type', 'base_rent'))->where('lease_id', $lease->id)->get();
    expect($rent)->toHaveCount(1)
        ->and((float) $rent->first()->total)->toBe(10000.0);
});

it('does NOT re-bill a fine whose invoice was CREDITED, but allows re-bill after it is CANCELLED', function () {
    $violation = finedViolation(fine: 1000);
    $invoice = $this->svc->bill($violation);

    // Credited keeps its AR + misc_income posting → still billed, no second invoice.
    $invoice->update(['status' => 'credited']);
    expect($violation->fresh()->isBilled())->toBeTrue()
        ->and($this->svc->bill($violation->fresh())->id)->toBe($invoice->id);

    // Cancelled voids the GL entry → the fine is free to re-bill as a fresh invoice.
    $invoice->update(['status' => 'cancelled']);
    expect($violation->fresh()->isBilled())->toBeFalse();
    expect($this->svc->bill($violation->fresh())->id)->not->toBe($invoice->id);
});

it('offers "Bill fine" only with invoices.create, and only for a fined, not-yet-billed violation', function () {
    $fined = finedViolation(fine: 2500);
    $noFine = finedViolation(fine: 0);

    // super_admin holds invoices.create → billable, but only when there IS a fine.
    $this->actingAs(makeUser('super_admin'));
    expect(ViolationResource::canBillFine($fined))->toBeTrue()
        ->and(ViolationResource::canBillFine($noFine))->toBeFalse();

    // A viewer holds violations.view but not invoices.create → cannot bill.
    $this->actingAs(makeUser('viewer'));
    expect(ViolationResource::canBillFine($fined))->toBeFalse();
});

it('hides "Bill fine" once the fine has been billed', function () {
    $this->actingAs(makeUser('super_admin'));
    $fined = finedViolation(fine: 2500);

    expect(ViolationResource::canBillFine($fined))->toBeTrue();

    $this->svc->bill($fined);

    expect(ViolationResource::canBillFine($fined->fresh()))->toBeFalse();
});

it('refuses to force-delete a billed violation, but still allows a reversible soft-delete', function () {
    $violation = finedViolation(fine: 1000);
    $this->svc->bill($violation);

    // Force-delete is blocked — it would sever the audit link to the live fine invoice.
    expect($violation->fresh()->forceDelete())->toBeFalse()
        ->and(Violation::withTrashed()->whereKey($violation->id)->exists())->toBeTrue();

    // Soft-delete is still allowed (reversible; billed_invoice_id stays on the trashed row).
    $violation->fresh()->delete();
    expect(Violation::whereKey($violation->id)->exists())->toBeFalse()
        ->and(Violation::withTrashed()->whereKey($violation->id)->first()->billed_invoice_id)->not->toBeNull();
});
