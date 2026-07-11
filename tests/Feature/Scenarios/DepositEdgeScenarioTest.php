<?php

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\DepositTransaction;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepositService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Edge scenarios for the deposits module (حركات التأمينات) — complements the happy
 * path in tests/Feature/Regression/DepositTransactionIntegrityTest.php.
 *
 * Each deposit row is one standalone GL event; tenant/asset are DERIVED from the lease
 * on save. The GL posting rides the real `accounting:sync-ledger` sweep
 * (DepositTransactionJournalizer). We drive the real command so the whole posting +
 * reversal surface stays balanced (near-real-time posting is off in the test env).
 *
 * Classes added here: state-transition (cancel is idempotent + terminal for the GL),
 * lease-derived asset scoping (flows through lease.unit), balanced GL on
 * receipt/refund/forfeit, and RBAC (which role can/cannot record deposits).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/** Post everything via the real full sweep. (dep_ prefix keeps these file-unique.) */
function dep_sync(): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
}

/** Posted (non-void) entries for a source. */
function dep_postedEntries(DepositTransaction $deposit)
{
    return JournalEntry::where('source_type', $deposit->getMorphClass())
        ->where('source_id', $deposit->getKey())
        ->where('status', 'posted')
        ->get();
}

function dep_voidedCount(DepositTransaction $deposit): int
{
    return JournalEntry::where('source_type', $deposit->getMorphClass())
        ->where('source_id', $deposit->getKey())
        ->where('status', 'void')
        ->count();
}

function dep_balanced(): bool
{
    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

// ── balanced GL: receipt / refund / forfeit each post a balanced 2-line entry ──────

it('posts a balanced entry for each of receipt, refund and forfeit', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    $types = ['receipt', 'refund', 'forfeit'];
    $deposits = collect($types)->map(fn (string $type) => DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => $type, 'amount' => 3000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]));

    dep_sync();

    foreach ($deposits as $deposit) {
        $entries = dep_postedEntries($deposit);
        expect($entries)->toHaveCount(1);

        // The iron rule: Σ debit = Σ credit for the entry.
        $lines = $entries->first()->lines;
        expect($lines)->toHaveCount(2);
        expect(round((float) $lines->sum('debit'), 2))->toBe(3000.00);
        expect(round((float) $lines->sum('credit'), 2))->toBe(3000.00);
    }

    // …and the books as a whole tie out.
    expect(dep_balanced())->toBeTrue();
});

// ── boundary: a zero-amount deposit posts NOTHING (journalizer skips amount <= 0) ──

it('does not post a GL entry for a zero-amount deposit but keeps the books balanced', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    // Blank amount is coerced to 0 on save (NOT-NULL column); the journalizer skips <= 0.
    $deposit = DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => 0,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);

    dep_sync();

    expect(dep_postedEntries($deposit))->toHaveCount(0);
    expect(dep_balanced())->toBeTrue();
});

// ── state-transition: cancel is idempotent and voids the GL entry (terminal) ───────

it('cancels a recorded deposit idempotently and voids its posted entry', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $deposit = DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => 8000,
        'transaction_date' => now()->toDateString(), 'method' => 'cash', 'status' => 'recorded',
    ]);

    dep_sync();
    expect(dep_postedEntries($deposit))->toHaveCount(1);

    $service = app(DepositService::class);
    $service->cancel($deposit);
    expect($deposit->fresh()->status)->toBe('cancelled');

    // Cancelling again is a no-op (already cancelled) — no exception, still cancelled.
    $service->cancel($deposit->fresh());
    expect($deposit->fresh()->status)->toBe('cancelled');

    // The next sweep voids the entry; a cancelled deposit posts nothing.
    dep_sync();
    expect(dep_voidedCount($deposit))->toBe(1);
    expect(dep_postedEntries($deposit))->toHaveCount(0);
    expect(dep_balanced())->toBeTrue();
});

// ── scoping: deposit is lease-derived — tenant + asset flow through lease.unit ─────

it('derives tenant and asset from the lease so the deposit is scoped to the correct property', function () {
    $assetA = makeAsset();
    $assetB = makeAsset();
    $leaseA = makeLease(makeUnit($assetA));
    $leaseB = makeLease(makeUnit($assetB));

    $depositA = DepositTransaction::create([
        'lease_id' => $leaseA->id, 'type' => 'receipt', 'amount' => 4000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);
    $depositB = DepositTransaction::create([
        'lease_id' => $leaseB->id, 'type' => 'receipt', 'amount' => 4000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);

    // Each deposit inherits its own lease's property + tenant — no cross-property leak.
    expect($depositA->refresh()->asset_id)->toBe($assetA->id);
    expect($depositA->tenant_id)->toBe($leaseA->tenant_id);
    expect($depositB->refresh()->asset_id)->toBe($assetB->id);
    expect($depositB->tenant_id)->toBe($leaseB->tenant_id);
    expect($assetA->id)->not->toBe($assetB->id);

    dep_sync();

    // The GL entry lands on the deposit's own property dimension (the books it belongs to).
    expect(dep_postedEntries($depositA)->first()->asset_id)->toBe($assetA->id);
    expect(dep_postedEntries($depositB)->first()->asset_id)->toBe($assetB->id);
    // Every line of A's entry carries A's dimension — nothing bleeds onto B.
    expect(dep_postedEntries($depositA)->first()->lines->pluck('asset_id')->unique()->all())
        ->toBe([$assetA->id]);
});

// ── RBAC: an accounting role may record deposits; a role without the perm may not ──

it('gates recording deposits on the deposit_transactions.create permission', function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // accounting has deposit perms; marketing does not; delete stays super_admin-only.
    $this->actingAs(makeUser('accounting'));
    expect(DepositTransactionResource::canCreate())->toBeTrue();
    expect(DepositTransactionResource::canViewAny())->toBeTrue();

    $this->actingAs(makeUser('marketing'));
    expect(DepositTransactionResource::canCreate())->toBeFalse();
    expect(DepositTransactionResource::canViewAny())->toBeFalse();

    // Delete is reserved for the platform owner regardless of the module permission.
    $deposit = DepositTransaction::create([
        'lease_id' => makeLease(makeUnit(makeAsset()))->id, 'type' => 'receipt', 'amount' => 1000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);
    $this->actingAs(makeUser('accounting'));
    expect(DepositTransactionResource::canDelete($deposit))->toBeFalse();
    $this->actingAs(makeUser('super_admin'));
    expect(DepositTransactionResource::canDelete($deposit))->toBeTrue();
});
