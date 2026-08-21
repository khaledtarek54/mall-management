<?php

/*
|--------------------------------------------------------------------------
| The modal opened, submitted, and did nothing (2026-08-18)
|--------------------------------------------------------------------------
| "Record deposit movement" offered a METHOD list taken from `admin.enums.method` — the PAYMENT
| methods: card, bank_transfer, instapay, wallet, cash, cheque, other. But
| `deposit_transactions.method` accepts exactly two values, `cash` and `bank`, and the field
| defaulted to `bank_transfer`.
|
| So every submission threw at the ValueSets listener and the operator saw nothing happen. Reported
| as "I tried to record deposit movement now and nothing happened"; found in the log, not on screen:
|
//     local.ERROR: "bank_transfer" is not one of the values method accepts. Allowed: cash, bank.
|
| The field now reads `admin.enums.expense_paid_from` — the same source the deposit resource's own
| form uses, which had it right all along. Two surfaces choosing their own option list for one column
| is the drift; the column's value set is the arbiter.
|
| Both tests here would have caught it. The first is cheap and general; the second is the one that
| matters, because it DRIVES the action rather than inspecting it — building an action in a test and
| asserting its shape proves nothing about whether pressing it works.
*/

use App\Filament\Admin\Actions\LeaseActions;
use App\Models\DepositTransaction;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'security_deposit' => 100000,
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

it('derives the method options from the column, so no surface can offer a value it refuses', function () {
    // Measured against the set the GUARD enforces, not against `allowed()`.
    //
    // Those were one list when this test was written and briefly became two: the payment-rail
    // catalogue widened `allowed()` (what a picker offers) and not `forTable()` (what
    // `ValueSets::guard()` accepts on save), so the deposit modal offered eight methods while the
    // listener took two — this exact bug, reintroduced, with this test still green because it
    // compared two things that had moved together. `forTable()` is the one that can refuse a save,
    // so it is the one an offer has to match.
    $enforced = ValueSets::forTable('deposit_transactions')['method'] ?? [];

    expect(array_keys(DepositTransaction::methodOptions()))->toBe($enforced)
        ->and($enforced)->not->toBeEmpty();
});

it('lets no Filament surface pick its own list for a deposit method', function () {
    // The failure was two surfaces choosing different option sets for one column: the deposit
    // resource had the right two by hand, the lease modal offered the PAYMENT methods and defaulted
    // to `bank_transfer`, which the column refuses. Both now read the derived set.
    $offenders = [];

    foreach ([
        app_path('Filament/Admin/Actions/LeaseActions.php'),
        app_path('Filament/Admin/Resources/DepositTransactions/Schemas/DepositTransactionForm.php'),
    ] as $file) {
        $body = (string) file_get_contents($file);

        if (str_contains($body, "Select::make('method')") && ! str_contains($body, 'DepositTransaction::methodOptions()')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'These offer their own deposit-method list: '.implode(', ', $offenders));
});

it('records the movement when the action is actually pressed', function () {
    $action = collect(LeaseActions::all())->firstWhere(fn ($a) => $a->getName() === 'recordDeposit');

    $action->record($this->lease);

    // The DEFAULTS matter as much as the options: this failed on the field's default value, which no
    // amount of asserting the action exists would have found.
    $action->call(['data' => [
        'type' => 'receipt',
        'method' => 'bank',
        'amount' => 30000,
        'transaction_date' => '2026-08-18',
        'notes' => null,
    ]]);

    expect(DepositTransaction::count())->toBe(1)
        ->and((float) $this->lease->fresh()->depositHeld())->toBe(30000.0);
});

it('refuses to give back more than is held, and says so', function () {
    $action = collect(LeaseActions::all())->firstWhere(fn ($a) => $a->getName() === 'recordDeposit');
    $action->record($this->lease);

    $action->call(['data' => [
        'type' => 'refund', 'method' => 'bank', 'amount' => 50000,
        'transaction_date' => '2026-08-18', 'notes' => null,
    ]]);

    // Nothing held, so there is nothing to refund — a negative liability would record the landlord
    // as owing money it never took.
    expect(DepositTransaction::count())->toBe(0);
});
