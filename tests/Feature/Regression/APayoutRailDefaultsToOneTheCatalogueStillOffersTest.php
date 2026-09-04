<?php

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Models\Disbursement;
use App\Models\PaymentMethod;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A money form opens on a rail the catalogue still offers (SW-116)
|--------------------------------------------------------------------------
| Since EG-11 a payment rail is a ROW the operator edits, and every money form takes its options
| from `PaymentMethod::optionsFor('<table>.<column>')`. Seven of them then stated their DEFAULT as
| a literal beside it — `->default('cash')`, `->default('bank_transfer')`,
| `->default(Disbursement::METHOD_BANK_TRANSFER)` — so the option list moved with the catalogue and
| the default did not.
|
| Retire the rail a form defaults to and the two disagree. Filament derives a Select's `Rule::in`
| from the options it resolved, and it cannot label a value it was not offered, so the field renders
| EMPTY while its state still carries the retired code: the operator submits a form they never
| touched that field on, and is refused with "the Method field is invalid". That is the 2026-08-18
| deposit bug — a form whose own default stopped being one of its options — through a different
| door, and this time it is the operator's own act of retiring a rail that opens it.
|
| Measured at HEAD 2026-09-04 against the dev database: deactivate `bank_transfer` and
| `PaymentMethod::optionsFor('disbursements.method')` answers
| [cash, cheque, card, instapay, other] — the schedule-payout modal's default is not among them.
|
| `PaymentMethod::defaultFor()` asks the SAME list the picker renders, so the two cannot drift, and
| answers NOTHING once the preferred rail is gone. Not a substitute rail: the rail decides which
| chart account the entry lands in (`accountIdOrFloor()`), so picking one for the operator would put
| money on a channel nobody chose. A blank required field asks the question instead.
*/
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset(['code' => 'RAIL']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('keeps the preferred rail while the catalogue still offers it', function () {
    // The control, and it must come first: with no rows at all the shipped floor supplies every
    // code, so nothing about this fix may change what an unconfigured install opens on.
    expect(PaymentMethod::optionsFor('disbursements.method'))->toHaveKey(Disbursement::METHOD_BANK_TRANSFER)
        ->and(PaymentMethod::defaultFor('disbursements.method', Disbursement::METHOD_BANK_TRANSFER))
        ->toBe(Disbursement::METHOD_BANK_TRANSFER);
});

it('drops the default the day the operator retires that rail', function () {
    PaymentMethod::create([
        'code' => Disbursement::METHOD_BANK_TRANSFER,
        'name_en' => 'Bank transfer', 'name_ar' => 'تحويل بنكي',
        'for_inbound' => true, 'for_outbound' => true,
        'is_active' => false,
    ]);

    // The picker and the default answer from one list — that is the whole property.
    expect(PaymentMethod::optionsFor('disbursements.method'))->not->toHaveKey(Disbursement::METHOD_BANK_TRANSFER)
        ->and(PaymentMethod::defaultFor('disbursements.method', Disbursement::METHOD_BANK_TRANSFER))->toBeNull();
});

it('opens the expense form on nothing once its rail is retired, and on cash while it is not', function () {
    // Driven through the REAL create page, because a default is evaluated when a form is built and
    // a unit test of the resolver would not notice a call site that never routed through it.
    Livewire::test(CreateExpense::class)
        ->assertOk()
        ->assertFormSet(['paid_from' => 'cash']);

    PaymentMethod::create([
        'code' => 'cash',
        'name_en' => 'Cash', 'name_ar' => 'نقدًا',
        'for_inbound' => true, 'for_outbound' => true,
        'is_active' => false,
    ]);

    Livewire::test(CreateExpense::class)
        ->assertOk()
        ->assertFormSet(['paid_from' => null]);
});

it('lets no money form state a rail default the catalogue is not asked about', function () {
    // The gate. Seven call sites carried a literal; the rule is that a rail picker's default comes
    // from the same seam its options do, so the eighth is covered by being written that way rather
    // than by somebody remembering this row.
    $offenders = [];
    $pickers = 0;

    foreach (filamentSources() as $path) {
        // Comments stripped: `DepositTransactionForm` names `DepositTransaction::methodOptions()`
        // in a comment a line above the call, and a gate that fires on a sentence gets weakened
        // rather than fixed.
        $lines = explode("\n", sourceWithoutComments($path));

        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'PaymentMethod::optionsFor(')
                && ! str_contains($line, 'DepositTransaction::methodOptions(')) {
                continue;
            }

            $pickers++;

            for ($j = $i + 1; $j < min($i + 16, count($lines)); $j++) {
                if (str_contains($lines[$j], '::make(')) {
                    break;
                }

                if (! str_contains($lines[$j], '->default(')) {
                    continue;
                }

                if (! str_contains($lines[$j], 'defaultFor(') && ! str_contains($lines[$j], 'defaultMethod(')) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' → '.trim($lines[$j]);
                }
            }
        }
    }

    // The premise. Nine rail pickers at HEAD 2026-09-04; a sweep that stopped matching would
    // report nothing and pass.
    expect($pickers)->toBeGreaterThanOrEqual(9);

    expect($offenders)->toBe([], "A rail picker states a default the catalogue is never asked about.\n"
        ."Route it through PaymentMethod::defaultFor('<table>.<column>', <preferred>) — or\n"
        ."DepositTransaction::defaultMethod() — so the default and the options read one list:\n  "
        .implode("\n  ", $offenders));
});
