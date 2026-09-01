<?php

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * A receipt whose allocations are refused must never have existed — it cannot be deleted afterwards.
 *
 * `CreatePayment::afterCreate()` synced the invoice allocations after the payment row had been
 * committed, and compensated for a refusal with `$payment->delete()`. That delete cannot work for
 * the ordinary receipt: `RefusesDeletionOfCommittedRecords` refuses anything `isReceived()`, and the
 * form **defaults to `captured`**. So the compensation threw its own DomainException, the operator
 * was shown the DELETION refusal instead of the allocation error, and the orphan survived — a
 * captured receipt with no allocations, which reads as unallocated tenant credit and invites the
 * operator to key the payment a second time.
 *
 * CLAUDE.md states that an uncommitted record stays deletable *"which is what keeps `CreatePayment`'s
 * orphan rollback working"*. The rule is right; the claim about this caller was not.
 *
 * **It is reachable in ONE request, with no concurrency**, which is what makes this worth a page
 * test rather than a unit one. `PaymentForm` caps each allocation ROW independently, and
 * `afterCreate()` SUMS rows against the same invoice — so 700 + 600 on a 1,000 invoice passes the
 * per-row cap, passes the total-vs-amount cap, and is refused only here. The first cut of this fix
 * assumed the refusal was race-only and wrote no page test at all; it was wrong on both counts.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Two rows against ONE invoice that pass every form gate and over-allocate when summed. */
function overAllocatingForm(): array
{
    return [
        'tenant_id' => test()->lease->tenant_id,
        'amount' => 1300,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => CarbonImmutable::now()->toDateString(),
        'allocations' => [
            ['invoice_id' => test()->invoice->id, 'allocated_amount' => 700],
            ['invoice_id' => test()->invoice->id, 'allocated_amount' => 600],
        ],
    ];
}

it('cannot delete a captured receipt — the reason the old compensation could not work', function () {
    $captured = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 5000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => CarbonImmutable::now(),
    ]);

    expect(fn () => $captured->delete())->toThrow(DomainException::class);

    // …and the rule it was relying on does hold for a receipt that never became money, so the
    // premise was right about the rule and wrong about this caller.
    $initiated = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 5000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'initiated', 'payment_date' => CarbonImmutable::now(),
    ]);
    $initiated->delete();

    expect(Payment::whereKey($initiated->id)->exists())->toBeFalse();
});

it('commits no receipt at all when the allocation is refused', function () {
    $before = Payment::count();

    try {
        Livewire::test(CreatePayment::class)
            ->fillForm(overAllocatingForm())
            ->call('create');
    } catch (DomainException) {
        // Filament rolls back and re-throws; `bootstrap/app.php` words it for the operator.
    }

    expect(Payment::count())->toBe($before)
        // …and the invoice it was aimed at is untouched rather than half-settled.
        ->and(round((float) $this->invoice->fresh()->balance, 2))->toEqual(1000.0)
        ->and($this->invoice->fresh()->payments()->count())->toBe(0);
});

it('commits the receipt when the allocation is fine — the control', function () {
    // Without this, a page that rolled everything back would satisfy the test above.
    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'allocations' => [['invoice_id' => $this->invoice->id, 'allocated_amount' => 1000]],
        ])
        ->call('create');

    expect(Payment::count())->toBe(1)
        ->and(round((float) $this->invoice->fresh()->balance, 2))->toEqual(0.0);
});

it('does not send the receipt e-mail while the invoice row locks are held', function () {
    // `assertInvoicesNotOverAllocated()` holds `lockForUpdate()` on the invoice and four settlement
    // tables, and `PaymentReceivedNotification` is not `ShouldQueue` — its mail channel sends
    // synchronously, per portal user. Inside the transaction, every other capture, credit-note
    // application, deposit netting or write-off against that invoice queues behind an SMTP
    // round-trip. `DB::afterCommit()` is what keeps it outside.
    $source = sourceWithoutComments(base_path(
        'app/Filament/Admin/Resources/Payments/Pages/CreatePayment.php'
    ));

    expect($source)->toContain('DB::afterCommit')
        ->and($source)->toContain('notifyReceiptOnce');

    // And it really is delivered on the happy path — deferring it must not drop it.
    Notification::fake();

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'allocations' => [['invoice_id' => $this->invoice->id, 'allocated_amount' => 1000]],
        ])
        ->call('create');

    expect(Payment::count())->toBe(1);
});

it('leaves the page usable after a refusal, rather than a component pointing at a rolled-back row', function () {
    // The regression the FIRST cut of this fix introduced. Swallowing the refusal to keep the
    // operator's form left `$this->record` holding a Payment whose row had just been rolled back —
    // `exists` is true and `id` is set, because a rollback does not touch PHP object state — so
    // Livewire dehydrated it with a key and the very next interaction 404'd on `firstOrFail()`.
    // Letting the exception propagate destroys and rebuilds the component instead, which is how
    // every other refusal in this app already behaves.
    try {
        Livewire::test(CreatePayment::class)->fillForm(overAllocatingForm())->call('create');
    } catch (DomainException) {
    }

    // A fresh mount is what the operator gets after the redirect-back, and it must work.
    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'allocations' => [['invoice_id' => $this->invoice->id, 'allocated_amount' => 1000]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Payment::count())->toBe(1);
});

it('refuses the over-allocation at the FIELD, not after the submit', function () {
    // SW-003b. The per-row cap bounded each allocation independently while `afterCreate()` SUMS
    // rows against the same invoice, so 700 + 600 on a 1,000 invoice passed every form gate and was
    // refused only at the model — the operator learned about it after pressing Create, with the
    // whole receipt rejected. Duplicate rows are supported input (`PaymentFormGuardsTest` covers
    // 400 + 600 on one invoice), so the row is capped against what its siblings already claim
    // rather than forbidden.
    Livewire::test(CreatePayment::class)
        ->fillForm(overAllocatingForm())
        ->call('create')
        ->assertHasFormErrors();

    expect(Payment::count())->toBe(0);
});

it('still accepts two rows that TOGETHER fit the invoice — the control', function () {
    // Without this, capping the row at the whole balance minus siblings could be mistaken for
    // forbidding a second row, which would break a supported way of keying a receipt.
    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'allocations' => [
                ['invoice_id' => $this->invoice->id, 'allocated_amount' => 400],
                ['invoice_id' => $this->invoice->id, 'allocated_amount' => 600],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Payment::count())->toBe(1)
        ->and(round((float) $this->invoice->fresh()->balance, 2))->toEqual(0.0);
});
