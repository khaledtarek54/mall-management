<?php

use App\Models\Concerns\RecordsBankAccount;
use App\Support\MoneyDocumentDoors;

/**
 * **A money document has more than one DOOR, and every one of them must ask the same question.**
 *
 * A security-deposit movement is recorded from the deposit register AND from the lease's own
 * Security deposit tab. A supplier payment from the bill's Edit page. An owner payout from the
 * statement run. Adding a field to the register's form reaches one door; the others go on quietly
 * recording the document with that field empty — with nothing red, because each file is correct on
 * its own and no test drives the door nobody thought about.
 *
 * That is not hypothetical. When `bank_account_id` became a real question on 2026-09-02, six forms
 * got it and `LeaseActions::recordDeposit()` did not — so every deposit taken from the lease page,
 * which is where an operator actually takes one, recorded no bank account at all. **Reported from
 * the panel, not by the suite**, a day after a change whose whole subject was that column.
 *
 * CLAUDE.md already stated the rule this broke, about this very pot: *enumerate the doors onto a pot
 * by grepping the pot, never from the diff that fixed one of them.* A sentence is not a gate. This
 * is the gate.
 *
 * Nothing here is listed by hand: the documents come from the models using {@see RecordsBankAccount},
 * each rail from that model's own `bankAccountRailColumn()`, and the doors from disk. A registry of
 * doors would go stale the moment somebody adds a screen — which is the failure being caught, so it
 * must not rest on the same person remembering.
 */
it('offers the bank account on every screen that asks how the money moved', function () {
    $missing = [];

    foreach (MoneyDocumentDoors::doors() as $path => $door) {
        if ($door['offersField'] || array_key_exists($path, MoneyDocumentDoors::EXEMPT)) {
            continue;
        }

        $missing[] = "{$path} — records a ".class_basename($door['model'])
            ." (asks for `{$door['rail']}`) and never asks which bank account it moved through";
    }

    expect($missing)->toBe([], implode("\n", array_merge($missing, [
        '',
        'Add `BankAccountField::for(<Document>::class)` beside the rail field. It brings the property',
        'scope, the default and the conditional requirement with it, so the door cannot disagree with',
        'the register. If the money genuinely cannot move through a bank account, register the door in',
        'MoneyDocumentDoors::EXEMPT with the reason.',
    ])));
});

/**
 * A field on a modal the write does not carry is a control that saves NOTHING.
 *
 * It renders, it validates, the operator picks an account, and the document records none — which is
 * strictly worse than not offering it, because the operator has been told the answer was taken. This
 * is already pinned for the two SERVICE writers in `TwoBanksInOneMallReconcileSeparatelyTest`
 * ("a field added to the form and not to the service is a control that saves NOTHING"); the same
 * trap lives one layer up, on any door that builds the row inline in its own `->action()`.
 *
 * Only answerable for a door that calls `Model::create([…])` itself — a resource form is saved by
 * Filament and a service door hands over an argument list, and both are covered elsewhere. `null`
 * means "not answerable here", not "fine".
 */
it('passes the bank account through on every door that builds the row itself', function () {
    $dropped = [];

    foreach (MoneyDocumentDoors::doors() as $path => $door) {
        if ($door['writesColumn'] !== false) {
            continue;
        }

        $dropped[] = "{$path} — builds a ".class_basename($door['model'])
            .' inline and never passes `bank_account_id`, so the field on its modal records nothing';
    }

    expect($dropped)->toBe([], implode("\n", $dropped));
});

/**
 * The sweep must be able to FIND something, in both directions.
 *
 * A gate that counts must assert it counted — this codebase has had three gates go silently blind
 * (a brace counter thrown by string interpolation, two discovery sweeps left reading a shape that
 * had moved) and each time the tell was the symptom, never the gate. Two premises here:
 *
 *   - the documents resolve at all, so a rename of the concern does not empty the sweep;
 *   - every document has at least ONE door, so a document whose only screen was deleted or renamed
 *     out of recognition is reported rather than silently dropping to zero doors and passing.
 */
it('is actually sweeping the panel', function () {
    // The ones that can HAVE a door: a door is a schema collecting the document's RAIL, so a
    // document with no rail column can have none by construction. `PostDatedCheque` carries a bank
    // account because a cheque is LODGED with one, and its rail is the paper — reporting it as
    // undoored would report the shape of the model rather than a gap.
    $documents = MoneyDocumentDoors::documentsWithARail();
    $doors = MoneyDocumentDoors::doors();

    expect($documents)->not->toBeEmpty('No document uses RecordsBankAccount — the sweep is reading the wrong shape.')
        ->and(count($doors))->toBeGreaterThanOrEqual(count($documents));

    $withoutADoor = array_diff(
        array_keys($documents),
        array_column($doors, 'model'),
    );

    expect($withoutADoor)->toBe([], 'These documents have no screen that asks how the money moved, so nothing '
        .'about them is being checked: '.implode(', ', array_map('class_basename', $withoutADoor)));
});

/** An exemption for a door that no longer exists is a claim nobody re-reads. */
it('keeps no stale exemption', function () {
    $stale = array_diff(array_keys(MoneyDocumentDoors::EXEMPT), array_keys(MoneyDocumentDoors::doors()));

    expect($stale)->toBe([], 'Exempted doors that are no longer doors: '.implode(', ', $stale));
});
