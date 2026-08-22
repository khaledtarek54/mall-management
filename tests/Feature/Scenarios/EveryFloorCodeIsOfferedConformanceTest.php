<?php

/*
|--------------------------------------------------------------------------
| A catalogue-backed picker must offer every value its column shipped with
|--------------------------------------------------------------------------
| `PaymentMethodPickersMatchTheirColumnTest` asks *offered ⊆ accepted* — nothing may be offered that
| the saving listener refuses. That is one half, and on its own it is satisfied by a picker that
| offers NOTHING.
|
| The other half went missing and cost a working screen. `deposit_transactions.method` accepts
| `cash|bank`; the rail catalogue seeds `bank_transfer` and has no `bank` row; the picker read rows
| first and only fell back to the floor when there were none. So on every SEEDED install `bank`
| vanished from the options while remaining an accepted value — and both deposit forms
| `->default('bank')`. Filament resolves a Select's `Rule::in` by labelling the submitted value,
| could not, and refused the submit as INVALID on a field the operator never touched. Editing a
| deposit already stored as `bank` broke the same way, and its label rendered as nothing.
|
| The rule, stated once: **a shipped code stays offered until a ROW retires it.** Retiring is a
| deliberate act with a row behind it; a code the catalogue simply never mentioned was not retired.
*/

use App\Models\DepositTransaction;
use App\Models\PaymentMethod;
use App\Support\ValueSets;
use Database\Seeders\PaymentMethodSeeder;

it('keeps every floor code offered on a seeded catalogue', function () {
    $this->seed(PaymentMethodSeeder::class);

    // The premise: seeding really did add rails, so what follows is about a populated catalogue and
    // not about an empty one falling back to the floor.
    expect(PaymentMethod::query()->count())->toBeGreaterThan(3);

    $missing = [];

    foreach (ValueSets::catalogueWidenedColumns() as $column => [$model, $reader]) {
        if ($model !== PaymentMethod::class) {
            continue;
        }

        [$table, $name] = explode('.', $column, 2);
        $floor = ValueSets::allowed($table, $name) ?? [];
        $offered = array_keys(PaymentMethod::optionsFor($column));

        foreach (array_diff($floor, $offered) as $code) {
            // A code with an INACTIVE row was retired on purpose and is allowed to be missing.
            $retired = PaymentMethod::query()->where('code', $code)->where('is_active', false)->exists();

            if (! $retired) {
                $missing[] = "{$column} no longer offers `{$code}`, which the column still accepts";
            }
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('offers the deposit form its own default', function () {
    $this->seed(PaymentMethodSeeder::class);

    // The specific break, pinned by name. Both deposit entry points hard-default to `bank`:
    // DepositTransactionForm and LeaseActions' modal.
    $options = DepositTransaction::methodOptions();

    expect($options)->toHaveKey('bank')
        // …and it must LABEL, because Filament resolves `Rule::in` through the option label and
        // treats an unlabellable value as no valid options at all.
        ->and($options['bank'])->not->toBeEmpty()
        ->and(PaymentMethod::labelFor('bank', 'admin.enums.expense_paid_from'))->not->toBe('bank');
});

it('keeps every accepted value reachable from the FILTER, retired or not', function () {
    // The other half, and the one the first cut got wrong on both sides. A form asks what may be
    // filed NOW; a filter asks what is already filed, and the column accepts floor ∪ active — so
    // anything in that set can be sitting in a row and must be findable. Pointing the filter at the
    // form's answer hid `bank` from the deposit filter on every seeded install, which is the only
    // value that column actually holds.
    $this->seed(PaymentMethodSeeder::class);

    PaymentMethod::create([
        'code' => 'wallet_retired', 'name_en' => 'Retired wallet', 'name_ar' => 'محفظة متوقفة',
        'for_inbound' => true, 'for_outbound' => true, 'is_active' => false,
    ]);

    $filter = PaymentMethod::filterOptionsFor('deposit_transactions.method', 'admin.enums.expense_paid_from');
    $form = PaymentMethod::optionsFor('deposit_transactions.method', 'admin.enums.expense_paid_from');

    expect($filter)->toHaveKey('bank')
        ->and($filter)->toHaveKey('wallet_retired')
        // …and the FORM still narrows, or the two would be the same list and `is_active` inert.
        ->and($form)->not->toHaveKey('wallet_retired');

    // `toHaveKey($key, $value)` takes an expected VALUE second, not a message — same family as the
    // variadic `toContain` trap this project has been bitten by twice. Collect and compare instead.
    $unreachable = array_values(array_diff(
        ValueSets::forTable('deposit_transactions')['method'],
        array_keys($filter),
    ));

    expect($unreachable)->toBe([], 'The filter cannot reach deposits recorded as: '.implode(', ', $unreachable));
});

it('drops a code the operator actually retired', function () {
    // The control for the rule above. "Still offered unless a row retires it" is only meaningful if
    // a row CAN retire it — otherwise this gate would just re-create the floor ∪ active union that
    // made `is_active` inert in the first place.
    $this->seed(PaymentMethodSeeder::class);

    PaymentMethod::create([
        'code' => 'bank', 'name_en' => 'Bank', 'name_ar' => 'بنك',
        'for_inbound' => true, 'for_outbound' => true, 'is_active' => false,
    ]);

    expect(array_keys(DepositTransaction::methodOptions()))->not->toContain('bank')
        // …and the column still ACCEPTS it, so the deposits already recorded on it are untouched.
        ->and(ValueSets::forTable('deposit_transactions')['method'])->toContain('bank')
        // …and it still labels, so their history reads.
        ->and(PaymentMethod::labelFor('bank'))->toBe('Bank');
});
