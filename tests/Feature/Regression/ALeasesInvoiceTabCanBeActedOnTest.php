<?php

declare(strict_types=1);

use App\Filament\Admin\RelationManagers\LeaseInvoicesRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A LIST YOU CANNOT ACT ON IS A DEAD END.
 *
 * The lease's Invoices tab had NO actions at all — not even a way to open the document. An
 * operator looking at the invoice they wanted to settle had to leave the lease, open the Payments
 * resource and find the same document by number: the six-screen loop UX5-03 removed from the
 * collections worklist and never removed from here, while the Billing forecast tab beside it has
 * linked to the invoice since it shipped.
 *
 * Both actions link to the REAL screens rather than opening thinner copies, for the reason the
 * tenant hub's record-payment action states in writing: the payment form owns the posting-date
 * guard, the property scope, the over-allocation backstop and the orphaned-receipt refusal.
 *
 * `?invoice=` fills the ALLOCATION as well as the tenant, and that is the half that matters —
 * `suggestAllocations()` spreads a receipt oldest-first, so filling the tenant alone would let a
 * payment raised to settle one invoice quietly land on another.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

function leaseWithAnOpenInvoice(): array
{
    $lease = Lease::factory()->create(['status' => 'active']);
    $asset = $lease->unit->asset;
    Filament::setTenant($asset);

    $invoice = Invoice::factory()->create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $asset->id,
        'status' => 'issued',
    ]);

    return [$lease->fresh(), $invoice->fresh()];
}

it('offers open and record-payment on an invoice with a balance', function (): void {
    [$lease, $invoice] = leaseWithAnOpenInvoice();

    $table = Livewire::test(LeaseInvoicesRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTable();

    $names = collect($table->getActions())->map(fn ($a) => $a->getName())->all();

    expect($names)->toContain('open', 'recordPayment');
});

/** The row action, resolved against one invoice. */
function paymentActionOn(Lease $lease, Invoice $invoice): ?Action
{
    return collect(
        Livewire::test(LeaseInvoicesRelationManager::class, [
            'ownerRecord' => $lease->fresh(),
            'pageClass' => EditLease::class,
        ])->instance()->getTable()->getActions()
    )->first(fn ($a) => $a->getName() === 'recordPayment')?->record($invoice);
}

it('does not offer to receive against a document that was never raised or has left the books', function (string $status): void {
    [$lease] = leaseWithAnOpenInvoice();

    // Built AT the status rather than flipped into it — `Invoice` refuses to return an issued
    // document to draft, so an update would test the fixture and not the rule.
    $invoice = Invoice::factory()->create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit->asset_id,
        'status' => $status,
    ]);

    expect(paymentActionOn($lease, $invoice->fresh())->isVisible())->toBeFalse();
})->with([
    'a draft has not been raised' => 'draft',
    'a cancelled invoice left the books' => 'cancelled',
]);

it('stops offering it once the invoice is settled', function (): void {
    [$lease, $invoice] = leaseWithAnOpenInvoice();

    // The control first: while there is a balance, the action IS offered. A refusal test whose
    // control never held would pass on an action that is never visible at all.
    expect((float) $invoice->balance)->toBeGreaterThan(0)
        ->and(paymentActionOn($lease, $invoice)->isVisible())->toBeTrue();

    // Settled through the real channel — `balance` is DERIVED by `recomputeTotals()`, so writing
    // it directly is the one thing the money invariants forbid.
    // No `asset_id` — a payment is scoped through the invoices it settles, not by a column.
    $payment = Payment::factory()->create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $invoice->balance,
        'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $invoice->balance]);
    $invoice->recomputeTotals();

    $invoice = $invoice->fresh();

    expect(round((float) $invoice->balance, 2))->toBe(0.0)
        ->and(paymentActionOn($lease, $invoice)->isVisible())->toBeFalse();
});

it('opens the payment form on the invoice, with the allocation filled', function (): void {
    [$lease, $invoice] = leaseWithAnOpenInvoice();

    $data = Livewire::withQueryParams(['invoice' => $invoice->getKey()])
        ->test(CreatePayment::class)
        ->get('data');

    expect((int) $data['tenant_id'])->toBe($invoice->tenant_id)
        ->and(round((float) $data['amount'], 2))->toBe(round((float) $invoice->balance, 2));

    // The allocation is the point — a receipt filled with the tenant alone spreads oldest-first.
    $allocation = collect($data['allocations'])->first();

    expect((int) $allocation['invoice_id'])->toBe($invoice->getKey())
        ->and(round((float) $allocation['allocated_amount'], 2))->toBe(round((float) $invoice->balance, 2));
});

it('records the receipt against the invoice the link named', function (): void {
    // ASSERTING THE FORM STATE PROVES THE PREFILL, NOT THE RECEIPT. `PrefillsCreateForm` writes
    // into a REPEATER, and the two ways that goes wrong both leave a plausible-looking state:
    // `fillPartially()`'s dotted `only()` drops the row values (measured — `invoice_id => null`
    // under a `minItems(1)` blank row), and writing the dotted leaves instead appends the real row
    // BESIDE that blank one, so the form opens with an empty required allocation. Both end here,
    // in what was actually banked.
    [$lease, $invoice] = leaseWithAnOpenInvoice();

    $balance = round((float) $invoice->balance, 2);

    Livewire::withQueryParams(['invoice' => $invoice->getKey()])
        ->test(CreatePayment::class)
        ->fillForm(['payment_date' => now()->toDateString(), 'method' => 'cash'])
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::query()->where('tenant_id', $invoice->tenant_id)->latest('id')->firstOrFail();

    expect($payment->invoices()->count())->toBe(1)
        ->and((int) $payment->invoices()->first()->getKey())->toBe($invoice->getKey())
        ->and(round((float) $payment->invoices()->first()->pivot->allocated_amount, 2))->toBe($balance)
        ->and(round((float) $invoice->fresh()->balance, 2))->toBe(0.0);
});

it('ignores an invoice the reader cannot see', function (): void {
    [$lease, $invoice] = leaseWithAnOpenInvoice();

    $elsewhere = Asset::factory()->create();
    $other = Invoice::factory()->create(['asset_id' => $elsewhere->id, 'status' => 'issued']);

    // Filament's tenant is still the FIRST property, so the resource's own scoped query cannot
    // reach this invoice. Prefilling a value the form would later refuse presents as the page
    // being broken rather than as a refusal, so it is dropped — the same rule `?tenant=` follows.
    $data = Livewire::withQueryParams(['invoice' => $other->getKey()])
        ->test(CreatePayment::class)
        ->get('data');

    expect($data['tenant_id'] ?? null)->not->toBe($other->tenant_id);
});
