<?php

use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\RelationManagers\TenantPaymentsRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\UnitOwnership;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A counterparty's receipts are on their Payments tab — all of them (SW-012)
|--------------------------------------------------------------------------
| The tenant hub's Payments tab scoped with `whereHas('invoices.lease.unit')`, a chain written
| before unit owners existed. `invoices.lease_id` is NULL for a unit-owner assessment BY
| CONSTRUCTION — `UnitOwnership::invoiceLinkAttributes()` returns `lease_id => null` and
| `Invoice::assertBelongsToExactlyOneAgreement()` enforces it — so the chain matched none of them.
| Measured on `mall_management_qa` 2026-09-03: 42 of 42 owner assessments carry a NULL lease.
|
| Two copies of the CORRECT predicate were already in the repo (`Tenant::creditBalance()`,
| `App\Support\TenantBalances`), one of them under a comment that names this exact mistake. Both
| now read `Payment::scopeInProperties()`, and so does this tab.
|
| Nothing was loud about it: the Payments REGISTER listed the same receipt throughout (it scopes
| from `#[PropertyOwned(via: 'invoices')]`), so the operator saw the money in one place and not in
| the other, on the screen that answers "what has this party paid us?".
*/

beforeEach(function () {
    seedRoles();
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->mall = makeAsset(['code' => 'AA', 'name' => 'Atriom Walk']);
    $this->otherMall = makeAsset(['code' => 'BB', 'name' => 'Nile Galleria']);

    // ONE counterparty in BOTH malls — a unit OWNER in AA and a retailer in BB. That pairing is
    // what makes the property scope a real question here rather than a formality: without it, a
    // scope that returned everything would satisfy every positive assertion below.
    $this->party = makeTenant(['name' => 'Mona Fahmy']);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->mall->id,
        'unit_id' => makeUnit($this->mall, ['code' => 'C-90'])->id,
        'tenant_id' => $this->party->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    // A property-RESTRICTED operator. `TenantScope::visibleAssetIds()` returns null for a super
    // admin, so the whole scope is a no-op there and a test run as one measures nothing at all.
    $this->operator = makeUser('manager', [$this->mall->id]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('lists an owner assessment receipt and a leased receipt, and still hides another mall\'s', function () {
    // (a) THE DEFECT — a receipt settling a unit owner's صيانة assessment.
    $assessment = assessmentFor($this->ownership, $this->party->id, 1000);
    expect($assessment->lease_id)->toBeNull();

    $ownerReceipt = Payment::create([
        'tenant_id' => $this->party->id, 'amount' => 1000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => '2026-03-05',
    ]);
    $ownerReceipt->invoices()->attach($assessment->id, ['allocated_amount' => 1000]);
    $assessment->recomputeTotals();

    // (b) THE CASE THAT ALWAYS WORKED — an ordinary leased receipt in the same mall. It is here so
    // that "the owner case works" cannot be satisfied by a change that breaks the path already right.
    $shopLease = makeLease(makeUnit($this->mall, ['code' => 'C-91']), $this->party, ['status' => 'active']);
    $shopInvoice = makeInvoice($shopLease, [
        'status' => 'issued', 'subtotal' => 700, 'vat_amount' => 0, 'total' => 700,
        'paid_amount' => 0, 'balance' => 700,
    ]);
    $leasedReceipt = Payment::create([
        'tenant_id' => $this->party->id, 'amount' => 700, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => '2026-03-06',
    ]);
    $leasedReceipt->invoices()->attach($shopInvoice->id, ['allocated_amount' => 700]);
    $shopInvoice->recomputeTotals();

    // (c) THE REFUSAL — the same counterparty trades in BB, and an operator holding only AA may not
    // read that receipt.
    $foreignLease = makeLease(makeUnit($this->otherMall), $this->party, ['status' => 'active']);
    $foreignInvoice = makeInvoice($foreignLease, [
        'status' => 'issued', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'paid_amount' => 0, 'balance' => 500,
    ]);
    $foreignReceipt = Payment::create([
        'tenant_id' => $this->party->id, 'amount' => 500, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => '2026-03-07',
    ]);
    $foreignReceipt->invoices()->attach($foreignInvoice->id, ['allocated_amount' => 500]);
    $foreignInvoice->recomputeTotals();

    $this->actingAs($this->operator);
    Filament::setTenant($this->mall);

    $rows = tableRows(Livewire::test(TenantPaymentsRelationManager::class, [
        'ownerRecord' => $this->party->fresh(),
        'pageClass' => EditTenant::class,
    ]))->pluck('id')->all();

    expect($rows)->toContain($ownerReceipt->id)
        ->and($rows)->toContain($leasedReceipt->id)
        ->and($rows)->not->toContain($foreignReceipt->id);
});

it('lists a cleared series cheque, which settles no invoice at all', function () {
    // The other half of the predicate, and the half a `whereHas('invoices', …)` alone would drop. A
    // series cheque names no invoice — the Egyptian norm — so a cleared one produces a captured
    // receipt with ZERO allocations and nothing to take a property from. The property is on the
    // cheque, which is where `Payment::originatingAssetId()` and `Tenant::creditBalance()` both
    // already read it.
    $mine = Payment::create([
        'tenant_id' => $this->party->id, 'amount' => 5000, 'currency' => 'EGP',
        'method' => 'cheque', 'status' => 'captured', 'payment_date' => '2026-08-02',
    ]);
    PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->mall->id,
        'tenant_id' => $this->party->id,
        'cheque_number' => 'CHQ-AA-'.uniqid(),
        'amount' => 5000,
        'cheque_date' => '2026-08-01',
        'received_date' => '2026-07-01',
        'status' => 'held',
        // saveQuietly: the register's own lifecycle guards belong to `PostDatedChequeService`, and
        // this fixture is about the SHAPE of the resulting receipt, not about clearing.
    ])->forceFill(['status' => 'cleared', 'cleared_payment_id' => $mine->id])->saveQuietly();

    // The control, and it must keep failing: an identical cheque lodged at the other mall.
    $theirs = Payment::create([
        'tenant_id' => $this->party->id, 'amount' => 900, 'currency' => 'EGP',
        'method' => 'cheque', 'status' => 'captured', 'payment_date' => '2026-08-03',
    ]);
    PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->otherMall->id,
        'tenant_id' => $this->party->id,
        'cheque_number' => 'CHQ-BB-'.uniqid(),
        'amount' => 900,
        'cheque_date' => '2026-08-01',
        'received_date' => '2026-07-01',
        'status' => 'held',
    ])->forceFill(['status' => 'cleared', 'cleared_payment_id' => $theirs->id])->saveQuietly();

    $this->actingAs($this->operator);
    Filament::setTenant($this->mall);

    $rows = tableRows(Livewire::test(TenantPaymentsRelationManager::class, [
        'ownerRecord' => $this->party->fresh(),
        'pageClass' => EditTenant::class,
    ]))->pluck('id')->all();

    expect($rows)->toContain($mine->id)
        ->and($rows)->not->toContain($theirs->id);
});
