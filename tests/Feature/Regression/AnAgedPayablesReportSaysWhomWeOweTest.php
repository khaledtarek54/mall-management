<?php

use App\Filament\Admin\Pages\ApAging;
use App\Filament\Admin\Pages\ArCollections;
use App\Filament\Admin\Pages\ReportHub;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Reports\ApAgingService;
use App\Services\VendorBillService;
use App\Services\VoidVendorBillPaymentService;
use App\Settings\ModulesSettings;
use App\Support\AgingBuckets;
use App\Support\ReportCatalogue;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Aged payables — whom we owe, how late, and whether the bills agree with the books (the reports
 * audit, 2026-09-12).
 *
 * The payables side had no ageing at all. `vendor_bills` carried `due_date` and `balance`, the
 * reconcile command already tied the AP control account to the bills, and the only screen was the
 * bill register with status tabs — so nothing answered the question a mall's accountant settles
 * every week: which suppliers do we pay first, and does what the bills say we owe agree with the
 * ledger. Every accounting system prints this beside the aged receivables; `ApAging` is the
 * payables mirror of `ArCollections`, reading `ApAgingService`.
 *
 * Every refusal here is paired with the control that must succeed, and the tie-out is proved in
 * BOTH directions — a ✓ that could not turn into ⚠ would be the reassurance this project refuses
 * to print.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * An approved supplier bill with a balance. `paid`/`balance` are written directly because the service
 * reads `balance` alone — the half-paid row is NOT the shape `recompute()` writes (that needs a
 * payment row and moves the status to `partially_paid`); the tie-out case below pays through the
 * real service instead, because there the ledger must agree with the register.
 */
function apBill(Vendor $vendor, int $assetId, float $total, string $billDate, ?string $dueDate, string $status = 'approved', float $paid = 0.0): VendorBill
{
    return VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $assetId,
        'category' => 'maintenance',
        'status' => $status,
        'bill_date' => $billDate,
        'due_date' => $dueDate,
        'subtotal' => $total,
        'vat_amount' => 0,
        'total' => $total,
        'paid_amount' => $paid,
        'balance' => $status === 'paid' ? 0 : $total - $paid,
    ]);
}

it('ages every open bill by its due date, per supplier, worst first', function () {
    $asOf = CarbonImmutable::parse('2026-09-13')->endOfDay();
    $late = Vendor::factory()->create(['name' => 'Cool-Air']);
    $young = Vendor::factory()->create(['name' => 'BrightSpark']);

    // BrightSpark FIRST, so insertion order disagrees with the order the report must open in — a
    // fixture whose natural order already matches the intended one proves nothing about sorting.
    // Due in ten days for 2,000 — current, never late.
    apBill($young, $this->asset->id, 2000, '2026-09-10', '2026-09-23');
    // Cool-Air: 5 days late for 1,000 and 100 days late for 4,000 — deepest bucket 90+.
    apBill($late, $this->asset->id, 1000, '2026-08-01', '2026-09-08');
    apBill($late, $this->asset->id, 4000, '2026-05-01', '2026-06-05');
    // …plus a half-paid bill that counts only what is still owed.
    apBill($late, $this->asset->id, 3000, '2026-08-20', '2026-09-01', paid: 2500);

    // Not payables: a draft is not on the books, a cancelled bill has left them, a paid one owes
    // nothing — and a bill dated AFTER the as-of day did not exist yet.
    apBill($young, $this->asset->id, 9999, '2026-09-01', '2026-09-05', status: 'draft');
    apBill($young, $this->asset->id, 8888, '2026-09-01', '2026-09-05', status: 'cancelled');
    apBill($young, $this->asset->id, 7777, '2026-09-01', '2026-09-05', status: 'paid', paid: 7777);
    apBill($young, $this->asset->id, 6666, '2026-09-20', '2026-09-25');

    $rows = app(ApAgingService::class)->byVendor($asOf);

    expect($rows)->toHaveCount(2)
        // Worst first: the supplier with the deepest bucket leads, whatever the totals.
        ->and($rows[0]['vendor']->name)->toBe('Cool-Air')
        ->and($rows[0]['buckets'])->toBe(['current' => 0.0, 'd_1_30' => 1500.0, 'd_31_60' => 0.0, 'd_61_90' => 0.0, 'd_90_plus' => 4000.0])
        ->and($rows[0]['total'])->toBe(5500.0)
        ->and($rows[0]['bill_count'])->toBe(3)
        ->and($rows[0]['oldest_days'])->toBe(100)
        ->and($rows[1]['vendor']->name)->toBe('BrightSpark')
        ->and($rows[1]['buckets']['current'])->toBe(2000.0)
        ->and($rows[1]['total'])->toBe(2000.0)
        ->and($rows[1]['oldest_days'])->toBe(0)
        // Every bucket is a `AgingBuckets` key — one policy for how late is late, shared with AR.
        ->and(array_keys($rows[0]['buckets']))->toBe(AgingBuckets::KEYS);
});

it('treats a bill with no terms as due the day it was dated', function () {
    // Reading a null due date as "never late" would hide exactly the bills nobody set terms on.
    $vendor = Vendor::factory()->create();
    apBill($vendor, $this->asset->id, 1000, '2026-07-01', null);

    $rows = app(ApAgingService::class)->byVendor(CarbonImmutable::parse('2026-09-13')->endOfDay());

    expect($rows[0]['oldest_days'])->toBe(74)
        ->and($rows[0]['buckets']['d_61_90'])->toBe(1000.0);
});

it('shows only the property the operator is standing in', function () {
    $other = makeAsset();
    $vendor = Vendor::factory()->create(['name' => 'Everywhere Ltd']);
    apBill($vendor, $this->asset->id, 1000, '2026-09-01', '2026-09-05');
    apBill($vendor, $other->id, 5000, '2026-09-01', '2026-09-05');

    $rows = app(ApAgingService::class)->byVendor(CarbonImmutable::parse('2026-09-13')->endOfDay());

    // The control — the row exists — and the refusal: the other mall's 5,000 is not in it.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total'])->toBe(1000.0);
});

it('says whether the bills tie out to the payables control account, in both directions', function () {
    $vendor = Vendor::factory()->create();
    $poster = app(LedgerPoster::class);

    // Two bills, both posted — the books and the register agree.
    $poster->sync(apBill($vendor, $this->asset->id, 1000, now()->toDateString(), now()->addDays(10)->toDateString())->fresh());
    $poster->sync(apBill($vendor, $this->asset->id, 2500, now()->toDateString(), now()->addDays(20)->toDateString())->fresh());

    // …and a posted bill in ANOTHER mall, which must disturb neither side of this mall's reading:
    // the control account is read for the same properties the bills are, or every mall would
    // report a delta that is really its neighbours.
    $poster->sync(apBill($vendor, makeAsset()->id, 5000, now()->toDateString(), now()->addDays(10)->toDateString())->fresh());

    $page = Livewire::test(ApAging::class)->assertOk();

    expect($page->instance()->getSubheading())
        ->toContain('EGP 3,500.00')
        ->toContain(__('admin.reports.ap_aging.ties_out'))
        ->not->toContain('⚠');

    // A third bill approved and NOT posted — the register says 4,000 owed, the ledger 3,500.
    apBill($vendor, $this->asset->id, 500, now()->toDateString(), now()->addDays(5)->toDateString());

    $subheading = Livewire::test(ApAging::class)->instance()->getSubheading();

    expect($subheading)->toContain('⚠')
        ->toContain('EGP 3,500.00')   // the control account
        ->toContain('EGP 4,000.00')   // the open bills
        ->toContain('EGP -500.00')    // ledger minus bills
        ->not->toContain(__('admin.reports.ap_aging.ties_out'));

    // A bill dated in the FUTURE that has already posted is in the ledger and not in today's
    // ageing — a mistyped date, and the ageing is where somebody notices it. The ⚠ compares the
    // control account against the total THIS page prints (now 4,000 unposted-included), never
    // against the reconciler's own bills figure, which would count the future bill on both sides
    // and print ✓ over two totals that differ.
    $poster->sync(apBill($vendor, $this->asset->id, 777, now()->addDays(3)->toDateString(), now()->addDays(30)->toDateString())->fresh());

    $subheading = Livewire::test(ApAging::class)->instance()->getSubheading();

    expect($subheading)->toContain('⚠')
        ->toContain('EGP 4,277.00')   // the control account: 3,500 posted + 777 posted
        ->toContain('EGP 4,000.00')   // what the page shows: 3,500 posted + 500 unposted, no future bill
        ->toContain('EGP 277.00');

    // A back-dated reading offers NO tie-out: bills are read at their current balance, so the
    // comparison would report a difference that is really the calendar.
    $backdated = Livewire::test(ApAging::class)->set('asOf', now()->subDay()->toDateString())->instance()->getSubheading();

    expect($backdated)->not->toContain('⚠')->not->toContain('✓');
});

it('reads the last payment from money that actually reached the supplier', function () {
    $vendor = Vendor::factory()->create();
    $bill = apBill($vendor, $this->asset->id, 1000, now()->subDays(20)->toDateString(), now()->subDays(10)->toDateString());

    // Paid 400 on the 15th — then the cheque bounced and the payment was voided.
    app(VendorBillService::class)->recordPayment($bill, 400, 'cash', now()->subDays(15));
    app(VoidVendorBillPaymentService::class)->void($bill->fresh()->payments()->first(), 'bounced');

    // The same supplier was paid in ANOTHER mall last week — not this mall's business.
    $elsewhere = apBill($vendor, makeAsset()->id, 900, now()->subDays(9)->toDateString(), now()->subDays(2)->toDateString());
    app(VendorBillService::class)->recordPayment($elsewhere, 900, 'cash', now()->subDays(7));

    $rows = app(ApAgingService::class)->byVendor(CarbonImmutable::now()->endOfDay());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total'])->toBe(1000.0)
        // A voided payment is not a payment, and a neighbour's payment is not ours: never paid.
        ->and($rows[0]['last_payment_at'])->toBeNull();

    // The control: a real, standing payment in THIS mall is the date shown.
    app(VendorBillService::class)->recordPayment($bill->fresh(), 100, 'cash', now()->subDays(3));

    expect(app(ApAgingService::class)->byVendor(CarbonImmutable::now()->endOfDay())[0]['last_payment_at'])
        ->toBe(now()->subDays(3)->toDateString());
});

it('links a row to the supplier only where the role may open it, and never into a 403', function () {
    $vendor = Vendor::factory()->create(['name' => 'Linked Ltd']);
    apBill($vendor, $this->asset->id, 100, now()->toDateString(), now()->addDays(5)->toDateString());

    $url = function (): ?string {
        $table = Livewire::test(ApAging::class)->assertOk()->instance()->getTable();

        return $table->getRecordUrl($table->getRecords()->first());
    };

    // `viewer` holds `vendors.view` and not `vendors.edit`, and `VendorResource` has no View page —
    // so the edit URL the first cut built was a link straight into a 403. Plain text instead.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    expect(ApAging::canAccess())->toBeTrue()
        ->and($url())->toBeNull();

    // The control: a role that may edit the supplier gets the way in.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    expect($url())->toContain('/vendors/'.$vendor->id.'/edit');
});

it('leaves an empty bucket blank on both ageing worklists', function () {
    $vendor = Vendor::factory()->create(['name' => 'One Bucket']);
    apBill($vendor, $this->asset->id, 8550, now()->subDays(40)->toDateString(), now()->subDays(35)->toDateString());
    makeInvoice(makeLease(makeUnit($this->asset)), ['due_date' => now()->subDays(35)->toDateString(), 'issue_date' => now()->subDays(40)->toDateString()]);

    // One bill in one bucket: four empty buckets, and none of them may print as EGP 0.00. Filament
    // joins the currency and the figure with a NO-BREAK space — a plain "EGP 0.00" here matched
    // nothing and passed with the blanking deleted; the control on the same page proves the form.
    $zero = "EGP\u{A0}0.00";

    Livewire::test(ApAging::class)->assertOk()->assertSee("EGP\u{A0}8,550.00")->assertDontSee($zero);
    Livewire::test(ArCollections::class)->assertOk()->assertSee("EGP\u{A0}")->assertDontSee($zero);
});

it('renders for the accounting role in both languages, with its buckets, its links and its export', function () {
    $vendor = Vendor::factory()->create(['name' => 'PureWater Plumbing']);
    apBill($vendor, $this->asset->id, 8550, now()->subDays(40)->toDateString(), now()->subDays(35)->toDateString());

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        $page = Livewire::test(ApAging::class)
            ->assertOk()
            ->assertSee('PureWater Plumbing')
            ->assertSee('8,550.00')
            ->assertSee(__('admin.reports.ap_aging.title'))
            ->assertSee(AgingBuckets::label('d_31_60'))
            // No raw key on the screen, in either language.
            ->assertDontSee('admin.reports.ap_aging');

        // The row's action lands on that supplier's UNPAID bills — the register `accounting` holds.
        $record = $page->instance()->getTable()->getRecords()->first();
        $billsUrl = $page->instance()->getTable()->getAction('openBills')->record($record)->getUrl();

        expect($billsUrl)->toContain('vendor-bills')
            ->and(urldecode($billsUrl))->toContain('filters[vendor_id][value]='.$vendor->id)
            ->and($billsUrl)->toContain('tab=unpaid')
            // The row click opens the SUPPLIER, and `accounting` holds `vendor_bills.view` without
            // `vendors.view` — so for this role the row is plain text rather than a link into a 403.
            ->and($page->instance()->getTable()->getRecordUrl($record))->toBeNull();

        // The CSV carries the same buckets under the same labels, and the figures.
        $csv = $page->instance()->reportCsv();

        expect($csv['headers'])->toContain(AgingBuckets::label('d_31_60'))
            ->and($csv['rows'][0][0])->toBe('PureWater Plumbing')
            ->and($csv['rows'][0][3])->toBe(8550.0)   // vendor · current · 1–30 · 31–60
            ->and($csv['filename'])->toStartWith('ap-aging-');
    }

    // …and for a role that CAN open the supplier register, the row is the way in.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $page = Livewire::test(ApAging::class)->assertOk();
    $record = $page->instance()->getTable()->getRecords()->first();

    expect($page->instance()->getTable()->getRecordUrl($record))->toContain('/vendors/'.$vendor->id);
});

it('is a catalogued, scheduled-deliverable report under its own Payables heading — and follows the Vendors switch', function () {
    expect(ReportCatalogue::REPORTS[ApAging::class]['category'])->toBe(ReportCatalogue::PAYABLES)
        ->and(ReportCatalogue::reportingPeriodOf(ApAging::class))->toBe(['asOf'])
        ->and(ReportCatalogue::deliverableOptions())->toHaveKey('ap_aging')
        ->and(ReportCatalogue::visibleTo())->toHaveKey(ReportCatalogue::PAYABLES)
        ->and(__('admin.report_hub.categories.payables'))->not->toBe('admin.report_hub.categories.payables');

    Livewire::test(ReportHub::class)->assertOk()->assertSee(__('admin.reports.ap_aging.nav_label'));

    // The control above; the refusal: a report on supplier bills belongs to the module that
    // records them, so switching Vendors off takes the ageing with it.
    app(ModulesSettings::class)->fill(['vendors' => false])->save();

    expect(ApAging::canAccess())->toBeFalse()
        ->and(ReportCatalogue::visibleTo())->not->toHaveKey(ReportCatalogue::PAYABLES);
});
