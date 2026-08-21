<?php

/*
|--------------------------------------------------------------------------
| A rail picker offers only what its own column accepts
|--------------------------------------------------------------------------
| `PaymentMethod::options()` used to take a DIRECTION and then take its floor from a hard-coded
| table — `vendor_bill_payments` for outbound, `payments` for inbound. Every outbound column
| therefore inherited the vendor-bill floor, and two of them accept something narrower:
| `expenses.paid_from` and `employee_advances.paid_from` hold `cash|bank`. On any database without
| the catalogue seeded — every fresh install, and every test — the expense form offered
| `bank_transfer`, `cheque`, `card` and `other` on a column that refuses all four. The operator picks
| one, the saving listener throws, and the button does nothing with no explanation.
|
| `OfferedValuesAreAcceptedValuesConformanceTest` could not see it, and the reason is worth keeping:
| that gate compares `ValueSets::allowed()` with `ValueSets::forTable()`, and both were right about
| the column. It was the PICKER that was reading somebody else's set.
|
| `optionsFor('table.column')` derives the direction AND the floor from the column, so the two can no
| longer disagree by construction. This pins it anyway, because "by construction" is a claim about
| code that can be edited.
*/

use App\Models\PaymentMethod;
use App\Support\ValueSets;

/** Every column the rail catalogue widens. */
function railColumns(): array
{
    return collect(ValueSets::catalogueWidenedColumns())
        ->filter(fn (array $entry) => $entry[0] === PaymentMethod::class)
        ->keys()
        ->all();
}

it('offers nothing a rail column would refuse, on an unseeded catalogue', function () {
    $columns = railColumns();

    // The premise: there are rail columns to check, and the catalogue really is empty here, so what
    // follows is about the FLOOR — the state of every fresh install.
    expect($columns)->not->toBeEmpty()
        ->and(count($columns))->toBeGreaterThanOrEqual(7)
        ->and(PaymentMethod::query()->count())->toBe(0);

    $offenders = [];

    foreach ($columns as $column) {
        [$table, $name] = explode('.', $column, 2);
        $accepted = ValueSets::forTable($table)[$name] ?? [];
        $offered = array_keys(PaymentMethod::optionsFor($column));

        if ($extra = array_values(array_diff($offered, $accepted))) {
            $offenders[] = "{$column} offers ".implode('/', $extra).' — the column accepts '.implode('/', $accepted);
        }

        if ($offered === []) {
            $offenders[] = "{$column} offers NOTHING, so the operator cannot record one at all.";
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
});

it('offers nothing a rail column would refuse once rails are activated either', function () {
    PaymentMethod::create([
        'code' => 'instapay', 'name_en' => 'InstaPay', 'name_ar' => 'انستا باي',
        'for_inbound' => true, 'for_outbound' => true,
    ]);

    $offenders = [];

    foreach (railColumns() as $column) {
        [$table, $name] = explode('.', $column, 2);
        $accepted = ValueSets::forTable($table)[$name] ?? [];
        $offered = array_keys(PaymentMethod::optionsFor($column));

        if ($extra = array_values(array_diff($offered, $accepted))) {
            $offenders[] = "{$column} offers ".implode('/', $extra);
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));

    // The premise for THIS half: activating a rail really did widen the columns, so the agreement
    // above is between two moving sets and not between two copies of the floor.
    expect(ValueSets::forTable('expenses')['paid_from'])->toContain('instapay')
        ->and(array_keys(PaymentMethod::optionsFor('expenses.paid_from')))->toContain('instapay');
});

it('sends each column to the right side of the catalogue', function () {
    // A collection network takes money IN and is nonsense as a way to pay a vendor. The direction is
    // derived from the registry, so this pins that the registry says what it should.
    PaymentMethod::create([
        'code' => 'fawry', 'name_en' => 'Fawry', 'name_ar' => 'فوري',
        'for_inbound' => true, 'for_outbound' => false,
    ]);

    expect(array_keys(PaymentMethod::optionsFor('payments.method')))->toContain('fawry')
        ->and(array_keys(PaymentMethod::optionsFor('employee_advance_repayments.method')))->toContain('fawry')
        ->and(array_keys(PaymentMethod::optionsFor('expenses.paid_from')))->not->toContain('fawry')
        ->and(array_keys(PaymentMethod::optionsFor('employee_advances.paid_from')))->not->toContain('fawry');
});
