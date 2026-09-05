<?php

/**
 * §9.3 gate 2 — A RULE MUST BE ON EVERY DOOR, NOT ON THE FIRST ONE.
 *
 * The deposit-cap shape, made permanent. Twice this sweep found an invariant enforced on the door
 * its author was thinking about and absent from the others: the deposit pot's cap was on the
 * lease-page modal while the register's own Create/Edit pages took a 500,000 refund on a 100,000
 * pot without a word, and the settlement floor (`InvoiceSettlement`) was applied on every channel
 * an OPERATOR drives and none a TENANT drives. Each time, the missing door was found by GREPPING
 * THE POT — and a sentence in CLAUDE.md telling the next author to do that is not a gate.
 *
 * This file is the grep, run on every build. For each named invariant it DERIVES the set of doors
 * from the code (token-stripped, so a class name in a comment is not a door — the exact false
 * match SW-228's review caught), and requires each door to be REGISTERED with the test that
 * drives it. A new creator of a deposit movement, or a new writer of any settlement channel,
 * fails this gate until somebody points at the test that drives the rule through it.
 *
 * What this gate does NOT prove: that the named test actually asserts the refusal — that was
 * mutation-proved when each test was written, and no static check can re-prove it. What it DOES
 * make impossible is the historical failure: a sixth door shipping with nobody ever asked the
 * question.
 */
const RULE_DOORS = [
    // ── The deposit pot: every creator of a deposit_transactions row, plus the register itself.
    // The cap lives on the MODEL (`saving`), so a new Eloquent door is covered by existing — but
    // it must still be REGISTERED here with the test that drives it, because a door could also
    // bypass the model (DB::table, saveQuietly) and only a named test proves it did not.
    'deposit-pot' => [
        'app/Services/SettleMoveOutService.php' => ['tests/Feature/Regression/AMoveOutCannotDisburseTheDepositTwiceTest.php', 'SettleMoveOutService'],
        'app/Filament/Admin/Actions/LeaseActions.php' => ['tests/Feature/Scenarios/EveryDoorOntoAMoneyDocumentAsksWhereItBankedTest.php', 'recordDeposit'],
        // The register's own Create/Edit pages — the door CLAUDE.md records as having had no cap
        // at all, where a 500,000 refund on a 100,000 pot saved without a word. It is driven by
        // the MODEL guard, which is where the cap lives precisely so a sixth door is covered by
        // existing rather than by being remembered — so the driving test is the one that proves
        // that guard, not the resource's own render smoke test.
        //
        // It was first registered against `DepositTransactionResourceTest` with the proof token
        // `DepositTransaction` — the class the file is NAMED after, so the token could never fail,
        // pointing at a 58-line file containing no cap, no refusal and no DomainException. A
        // registry row that cannot be wrong is a row that documents nothing.
        'app/Filament/Admin/Resources/DepositTransactions/DepositTransactionResource.php' => ['tests/Feature/Regression/AMoveOutCannotDisburseTheDepositTwiceTest.php', 'refuses a refund larger than the pot'],
    ],

    // ── Invoice settlement: every writer of any of the four channels (payment pivot, tenant
    // credit, deposit application, applied credit note). The floor is `InvoiceSettlement`; the
    // named test must drive THIS door against a relieved invoice, or drive the route it serves.
    'invoice-settlement' => [
        'app/Filament/Admin/Resources/Payments/Pages/CreatePayment.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'CreatePayment'],
        'app/Filament/Admin/Resources/Payments/Pages/EditPayment.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'EditPayment'],
        'app/Services/PostDatedChequeService.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'PostDatedChequeService'],
        'app/Services/ApplyTenantCreditService.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'ApplyTenantCreditService'],
        'app/Services/ApplyDepositToInvoiceService.php' => ['tests/Feature/Regression/ADepositNeverPaysForgivenDebtTest.php', 'ApplyDepositToInvoiceService'],
        'app/Services/CreditNoteService.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'CreditNoteService'],
        // The model itself. `refitAllocationsToBalance()` rewrites the pivot with
        // `updateExistingPivot`, a spelling the first derivation could not see — so `Payment.php`,
        // which contains neither `attach(` nor `sync(`, sat outside the door set entirely. It only
        // ever REDUCES an allocation (to the settleable amount, net of write-offs), so it is not a
        // way to over-settle; it is registered because a door nobody is asked about is how the
        // next one ships unguarded.
        'app/Models/Payment.php' => ['tests/Feature/Regression/ARelievedInvoiceAcceptsNoMoreMoneyTest.php', 'assertInvoicesNotOverAllocated'],
        // The two gateway doors are driven through the ROUTES they serve — `/pay/{token}` and the
        // portal buttons — which is the honest door for an unauthenticated surface.
        'app/Services/Paymob/PaymobPaymentInitiator.php' => ['tests/Feature/Regression/APublicPayLinkCannotCollectWhatIsNotOwedTest.php', 'integrations.paymob.enabled'],
        'app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php' => ['tests/Feature/Regression/APublicPayLinkCannotCollectWhatIsNotOwedTest.php', "/demo'"],
    ],
];

/**
 * Comment-stripped source of one app file, memoised per process.
 *
 * token_get_all, not a regex: a bracket- or line-counting stripper fails OPEN on a delimiter
 * inside a string, which is the exact fault SW-228's review found in an earlier door sweep.
 * (Same 6 lines as `MoneyDocumentDoors::withoutComments`, which is deliberately private —
 * blanked to spaces so offsets stay meaningful in failure messages.)
 */
function ruleDoorSource(string $relative): string
{
    static $cache = [];

    if (! isset($cache[$relative])) {
        $out = $source = file_get_contents(base_path($relative));
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $at = strpos($out, $token[1]);
                if ($at !== false) {
                    $out = substr_replace($out, str_repeat(' ', strlen($token[1])), $at, strlen($token[1]));
                }
            }
        }
        $cache[$relative] = $out;
    }

    return $cache[$relative];
}

/** Every app/ php file, relative paths, memoised. */
function ruleDoorAppFiles(): array
{
    static $files;
    if ($files === null) {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $f->getPathname()), '/');
            }
        }
        sort($files);
    }

    return $files;
}

function deriveDoors(string $invariant): array
{
    $doors = [];
    foreach (ruleDoorAppFiles() as $rel) {
        $src = ruleDoorSource($rel);
        $hit = match ($invariant) {
            // A creator of a deposit movement, or the register whose pages create through Filament
            // (a Filament page never names the model in a ::create call, so the resource's own
            // $model declaration is the door's signature).
            'deposit-pot' => str_contains($src, 'DepositTransaction::create(')
                || str_contains($src, 'DepositTransaction::updateOrCreate(')
                || str_contains($src, 'DepositTransaction::firstOrCreate(')
                || preg_match('/new DepositTransaction\b/', $src) === 1
                || preg_match('/->deposit(?:s|Transactions)\(\)->(?:create|updateOrCreate|firstOrCreate)\(/', $src) === 1
                || preg_match('/\$model\s*=\s*DepositTransaction::class/', $src) === 1,
            // A writer of any settlement channel. The credit-note channel's signature is the
            // ADDITION to credit_applied_amount — releases subtract and are not settlements.
            // Every spelling this codebase uses on these pivots, not the handful the first draft
            // happened to think of: `Payment::refitAllocationsToBalance()` already writes
            // `invoices()->updateExistingPivot(...)` and `Payment.php` contains neither `attach(`
            // nor `sync(`, so that file was outside the derived set and nobody was ever asked to
            // register it. (That call only ever REDUCES an allocation, so it was not itself a
            // hole — the point is that the sweep demonstrably could not see a spelling in use on
            // the very pivot it guards.)
            'invoice-settlement' => str_contains($src, 'invoices()->attach(')
                || str_contains($src, 'invoices()->sync(')
                || str_contains($src, 'invoices()->syncWithoutDetaching(')
                || str_contains($src, 'invoices()->updateExistingPivot(')
                || str_contains($src, 'TenantCreditApplication::create(')
                || str_contains($src, 'TenantCreditApplication::updateOrCreate(')
                || str_contains($src, 'DepositApplication::create(')
                || str_contains($src, 'DepositApplication::updateOrCreate(')
                || preg_match('/credit_applied_amount\s*=\s*[^;=]*\+/', $src) === 1
                || preg_match('/increment\(\s*.credit_applied_amount./', $src) === 1,
            default => false,
        };
        if ($hit) {
            $doors[] = $rel;
        }
    }

    return $doors;
}

it('derives at least the doors it was written against — the sweep collects something', function () {
    // A gate that silently stops collecting reports on a set it no longer sees (three recorded
    // instances). Each derivation must find at least the doors known on the day it was written.
    expect(count(deriveDoors('deposit-pot')))->toBeGreaterThanOrEqual(3)
        ->and(count(deriveDoors('invoice-settlement')))->toBeGreaterThanOrEqual(8);
});

it('registers every derived door, and no door that no longer exists', function () {
    foreach (RULE_DOORS as $invariant => $registered) {
        $derived = deriveDoors($invariant);
        $known = array_keys($registered);
        sort($derived);
        sort($known);

        $new = array_values(array_diff($derived, $known));
        $stale = array_values(array_diff($known, $derived));

        expect($new)->toBe([],
            "[$invariant] NEW door(s) with no registered driving test — a rule must be on every door: ".implode(', ', $new));
        expect($stale)->toBe([],
            "[$invariant] registered door(s) that no longer write — remove the stale row: ".implode(', ', $stale));
    }
});

it('names a real test for every door, and that test really names the door', function () {
    foreach (RULE_DOORS as $invariant => $registered) {
        foreach ($registered as $door => [$test, $proof]) {
            expect(file_exists(base_path($test)))->toBeTrue("[$invariant] $door: test file missing — $test");

            $src = file_get_contents(base_path($test));
            expect(str_contains($src, $proof))->toBeTrue(
                "[$invariant] $door: its registered test ($test) no longer contains '$proof' — the claim that this door is driven is unverifiable");
        }
    }
});
