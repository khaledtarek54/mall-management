<?php

use App\Models\JournalEntry;
use App\Models\SlaPenalty;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\ApplySlaPenaltyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Re-homing a vendor bill must carry its SLA penalties to the new property.
 *
 * `VendorBill::ledgerChildRelations()` returned `[$this->payments()]` and omitted `penalties()` —
 * the third instance of the child-source cascade class this project has already fixed twice.
 *
 * `SlaPenaltyJournalizer` derives its ENTIRE payload from the parent bill, `asset_id`
 * included, so a penalty is exactly as dependent on the bill as a payment is. Move a bill between
 * properties and only the payments were bumped: the penalty's `updated_at` never moved, the
 * two-day windowed sweep never looked at it, and the FIRST property kept an expense credit and an
 * AP debit for a bill that is not theirs. It self-healed on the Friday `--all` run — and never at
 * all if the month closed before then.
 *
 * Driven through the real sweep rather than `LedgerPoster::post()`, because the whole defect lives
 * in what the *window* selects. A test that posts directly proves only the journalizer's
 * arithmetic and would have passed throughout.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->from = makeAsset(['code' => 'FROM']);
    $this->to = makeAsset(['code' => 'TO']);
    $vendor = Vendor::create(['name' => 'SlaCo', 'category' => 'hvac', 'status' => 'active']);

    $this->bill = VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->from->id,
        'status' => 'approved',
        'category' => 'hvac',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000,
        'paid_amount' => 0, 'balance' => 50000,
    ]);

    // A penalty hangs off a work order and reaches `applied` through its own service — the state
    // is not settable by hand, and a fixture that wrote it directly would be green over a path
    // no operator can take.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->from->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'category' => 'hvac',
        'priority' => 'urgent',
        'scheduled_for' => now()->toDateString(),
    ]);

    $this->penalty = SlaPenalty::create([
        'facility_work_order_id' => $order->id,
        'asset_id' => $this->from->id,
        'vendor_id' => $vendor->id,
        'basis' => SlaPenalty::BASIS_FLAT,
        'rate' => 8000,
        'hours_over_sla' => 0,
        'amount' => 8000,
        'status' => SlaPenalty::STATUS_FINAL,
        'finalised_at' => now(),
    ]);

    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);
    $this->penalty->refresh();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // AGE THE PENALTY OUT OF THE SWEEP WINDOW. The scheduled run only visits rows touched in the
    // last two days, and the entire defect is that the penalty was never brought back into that
    // window. Without this, every row here is seconds old, the sweep picks the penalty up on its
    // own, and the test passes whether or not the cascade exists — which is exactly what the first
    // version of this file did.
    $this->travel(10)->days();
});

/** The asset the penalty's live posted entry sits on, or null when it has none. */
function penaltyEntryAssetId(SlaPenalty $penalty): ?int
{
    return JournalEntry::query()
        ->where('source_type', $penalty->getMorphClass())
        ->where('source_id', $penalty->id)
        ->where('status', 'posted')
        ->value('asset_id');
}

it('posts the penalty against the bill\'s property to begin with', function () {
    // The control: without this, the re-home assertion below could pass because nothing ever posted.
    expect(penaltyEntryAssetId($this->penalty))->toBe($this->from->id);
});

it('moves the penalty entry when the bill is re-homed, on the windowed sweep', function () {
    $this->bill->update(['asset_id' => $this->to->id]);

    // The windowed run — NOT --all. The bug is precisely that the window never selected the
    // penalty, so only the Friday full run healed it.
    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    expect(penaltyEntryAssetId($this->penalty))->toBe($this->to->id);
});

it('leaves the first property with no live penalty entry after the move', function () {
    $this->bill->update(['asset_id' => $this->to->id]);
    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    $stranded = JournalEntry::query()
        ->where('source_type', $this->penalty->getMorphClass())
        ->where('source_id', $this->penalty->id)
        ->where('status', 'posted')
        ->where('asset_id', $this->from->id)
        ->exists();

    expect($stranded)->toBeFalse();
});

it('voids the penalty entry on the windowed sweep when the bill is cancelled', function () {
    // The dependency in the other direction. Cancel, not delete: a bill on the books REFUSES
    // deletion (`DeletionPolicy` / `RefusesDeletionOfCommittedRecords`), so a delete-based test
    // here would be exercising a path no operator can take.
    $this->bill->update(['status' => 'cancelled']);

    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    // A cancelled bill is not postable, so the penalty deducted from it has no basis either.
    expect(penaltyEntryAssetId($this->penalty))->toBeNull();
});

it('does not touch a penalty when an unrelated field on the bill changes', function () {
    // The paired control on the re-home bump: if the hook fired on every update it would re-derive
    // (and therefore void-and-repost) posted entries on every incidental edit, which is churn in
    // the audit trail rather than correctness.
    $before = $this->penalty->fresh()->updated_at;

    $this->travel(2)->seconds();
    $this->bill->update(['notes' => 'Chased the vendor.']);

    expect($this->penalty->fresh()->updated_at->equalTo($before))->toBeTrue();
});
