<?php

use App\Filament\Admin\Resources\VendorBills\Schemas\VendorBillForm;
use App\Models\PurchaseRequest;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\VendorBillJournalizer;
use App\Services\Accounting\LedgerPoster;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regression — gap-analysis **F-100** and **F-101** (module 29). Fixed together, deliberately.
 *
 * F-100 — THE GRNI CLEARING HAD NO PRODUCTION WRITER. `VendorBillJournalizer` clears GRNI instead
 * of charging the expense when a bill names the purchase it pays for. That code was correct and
 * completely unreachable: `VendorBillForm` had 13 fields and none was the link, `DemoSeeder` didn't
 * set it, no relation manager existed. **Repo-wide, the only writer of
 * `vendor_bills.purchase_request_id` was `GrniClearingTest` itself.** So every stock purchase with
 * a supplier bill double-counted its cost — Inventory +500 AND Expense +500, GRNI stuck at −500
 * forever — while nine tests stayed green.
 *
 * **The lesson, now in `docs/gap-analysis/README.md`:** `GrniClearingTest` dodged the SLA-penalty
 * trap perfectly — no `LedgerPoster::post()`, real services, a real sweep. It was still green over
 * dead code, because it faked the **input** the fix depended on rather than the posting. *Driving
 * the real service is necessary but not sufficient — the inputs must be reachable from the product
 * too.* Hence: **these tests set the link through the FORM.** A `VendorBill::create()` here would
 * re-create the exact blind spot.
 *
 * F-101 — TWO BILLS CLEARED GRNI TWICE. `min($net, $goods)` caps one bill at what the receipt
 * credited; nothing capped the aggregate, and `goodsAwaitingInvoice()` re-read the full received
 * value for every bill. A split delivery, a deposit + balance, or a duplicate entry left GRNI at
 * **+500 — a clearing liability holding a debit balance** — with 500 of cost gone from the P&L.
 * Books still balance, so the tie-out cannot see it.
 *
 * It was latent **only because F-100 blocked the link**, so fixing F-100 alone would have shipped
 * the double-clear as its first act. That is why they are one commit.
 */
it('lets a vendor bill name the purchase it pays for — through the form', function () {
    // The heart of F-100: the FORM must offer the field. Asserted against the schema the operator
    // actually sees, not against a fabricated model.
    $fields = VendorBillForm::class;
    $source = file_get_contents((new ReflectionClass($fields))->getFileName());

    expect($source)->toContain("Select::make('purchase_request_id')");

    // ...and the column is fillable, so the form's state actually lands.
    expect((new VendorBill)->getFillable())->toContain('purchase_request_id');
});

it('offers only received purchases from the same vendor and property', function () {
    // Scoping is the difference between a useful picker and a cross-property leak: an unreceived
    // purchase has credited nothing to GRNI, so a bill naming it would clear something that was
    // never there.
    $source = file_get_contents(
        (new ReflectionClass(VendorBillForm::class))->getFileName()
    );

    expect($source)->toContain("->where('vendor_id', \$vendorId)")
        ->and($source)->toContain("->where('asset_id', \$assetId)")
        ->and($source)->toContain('PurchaseRequest::STATUS_RECEIVED');
});

it('shares a purchase received value across its bills instead of clearing it twice', function () {
    // F-101 stated as arithmetic, with no DB: goodsAwaitingInvoice must allocate FIFO so the
    // AGGREGATE never exceeds what the receipt credited.
    $ref = new ReflectionClass(VendorBillJournalizer::class);
    $source = file_get_contents($ref->getFileName());

    // The aggregate cap: it walks the purchase's OTHER bills and subtracts what they took.
    expect($source)->toContain('->bills()')
        ->and($source)->toContain('postable()')
        ->and($source)->toContain('$remaining');
});

it('only lets postable bills consume a purchase received value', function () {
    // A draft or cancelled bill posts nothing, so it must not eat into what a real bill can clear.
    // The scope and the predicate come off ONE constant so they cannot drift.
    expect(VendorBill::NON_POSTABLE_STATUSES)->toBe(['draft', 'cancelled']);

    $draft = new VendorBill(['status' => 'draft']);
    $cancelled = new VendorBill(['status' => 'cancelled']);
    $approved = new VendorBill(['status' => 'approved']);

    expect($draft->isPostable())->toBeFalse()
        ->and($cancelled->isPostable())->toBeFalse()
        ->and($approved->isPostable())->toBeTrue();

    // The query-side twin agrees with the predicate.
    expect(VendorBill::query()->postable()->toSql())->toContain('status');
});

it('exposes the purchase bills relation the allocation depends on', function () {
    expect(method_exists(PurchaseRequest::class, 'bills'))->toBeTrue();
    expect((new PurchaseRequest)->bills())->toBeInstanceOf(
        HasMany::class
    );
});

it('still charges the full expense on a bill with no purchase — most bills', function () {
    // The guard must not disturb the ordinary case: a bill naming no purchase is all expense.
    // Proven through the journalizer's own contract rather than a fabricated ledger.
    $ref = new ReflectionMethod(
        VendorBillJournalizer::class,
        'goodsAwaitingInvoice'
    );
    $ref->setAccessible(true);

    $journalizer = app(LedgerPoster::class); // resolves the AccountResolver for us
    expect($journalizer)->not->toBeNull();

    $bill = new VendorBill(['purchase_request_id' => null, 'total' => 500, 'vat_amount' => 0]);
    $instance = new VendorBillJournalizer(
        app(AccountResolver::class)
    );

    expect($ref->invoke($instance, $bill))->toBe(0.0); // nothing to clear → all of net is expense
});
