<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Models\AccountingPeriod;
use App\Models\CreditNote;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CreditNoteService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * A credit note's status is the outcome of an act, and the form offered the acts as plain options.
 *
 * Every non-draft status here is derived or act-driven, and each act carries its own permission:
 * `issued` comes from the Issue button (`credit_notes.issue`), `applied` is derived from the
 * remaining balance by `applyToInvoice()`, and `void` comes from the Void button
 * (`credit_notes.void`). **The status Select needed only `credit_notes.edit`**, so it was a way to
 * produce all three effects with none of their checks — and a role deliberately denied
 * `credit_notes.void` could void a note through it.
 *
 * What the acts do and the Select skipped:
 *
 * - **Issue** asserts the accounting period is open. Without it the AR effect commits while the
 *   journal post is silently refused inside the best-effort sync — the operator reads "Saved" and
 *   the ledger never moves. That is the failure the posting-date guards exist for, and it was
 *   reachable from an ordinary edit form.
 * - **Void** refuses a note with applied credit (voiding it strands the application), zeroes the
 *   balance, stamps `voided_at`, records WHY in the audit trail, and lets the poster reverse the
 *   entry.
 * - **`applied`** marks a note fully spent. Picked while a balance still stands, the credit becomes
 *   invisible to every picker that narrows on `hasBalance()` and the tenant simply loses it.
 *
 * Same shape, same reasoning, as `cancelled`/`written_off` on the invoice form (`9c970144`). The
 * options are gone from the picker, and `CreditNote::booted` is the gate — because a Select is a UI
 * truth and the value still arrives in the Livewire payload.
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
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function noteWorth(float $total, string $status = 'draft'): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => test()->lease->tenant_id,
        'lease_id' => test()->lease->id,
        'asset_id' => test()->asset->id,
        'status' => $status,
        'issue_date' => CarbonImmutable::now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => $total, 'vat_amount' => 0, 'total' => $total,
        'applied_amount' => 0, 'balance' => $total, 'currency' => 'EGP',
    ]);

    // WITH A LINE ITEM. The form's repeater is `minItems(1)`, so a note without one fails on
    // `data.items` and every save assertion below would read as a fixture problem rather than as
    // the defect it is meant to catch.
    $note->items()->create([
        'description' => 'Adjustment',
        'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
    ]);

    return $note->fresh();
}

/**
 * The `status` Select anywhere in a schema, however deeply nested.
 *
 * RECURSIVE, and through `getChildSchemas()`: a Section's fields hang off child schemas rather than
 * off the component, so a one- or two-level read finds the container and none of the fields. That
 * is the traversal mistake `AClearableFilterNeverBreaksItsPageTest` was written around, hit again
 * here two hours later.
 */
function findStatusSelect(object $node): ?Select
{
    $children = method_exists($node, 'getComponents') ? $node->getComponents(withHidden: true) : [];

    foreach ($children as $component) {
        if ($component instanceof Select && $component->getName() === 'status') {
            return $component;
        }

        if (method_exists($component, 'getChildSchemas')) {
            try {
                foreach ($component->getChildSchemas() as $child) {
                    if ($found = findStatusSelect($child)) {
                        return $found;
                    }
                }
            } catch (Throwable) {
                // A component that cannot be walked detached — skipped rather than fatal.
            }
        }
    }

    return null;
}

/**
 * The options the form actually RENDERS for this record — read from the built component, not from
 * the source, because a call site can chain `->options()` again and look correct in review.
 */
function creditNoteStatusOptions(CreditNote $note): array
{
    // **The PAGE's own schema, not a freshly configured one.** `Schema::make($page)` carries no
    // record, so the options closure sees `$record === null` and takes the DRAFT branch — which is
    // how the first version of this helper reported a voided note as offering neither `void` nor
    // `applied` and proved nothing about the branch that actually runs.
    $page = Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])->instance();

    return findStatusSelect($page->getSchema('form'))?->getOptions() ?? [];
}

/** Whether the status field is editable for this record. */
function creditNoteStatusIsEditable(CreditNote $note): bool
{
    $page = Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])->instance();

    return ! (findStatusSelect($page->getSchema('form'))?->isDisabled() ?? true);
}

it('does not offer void or applied on the one note where a status can be picked', function () {
    // A DRAFT note is the only state where the choice means anything, so it is the only state where
    // the narrowing matters. Asserting it on an `issued` note would prove nothing either way, which
    // is what the first version of this test did.
    $note = noteWorth(5000);

    $offered = creditNoteStatusOptions($note);

    expect($offered)->not->toBeEmpty()
        // The vocabulary still HAS them — this is a removal from one picker, not a rename.
        ->and(__('admin.statuses.credit_note'))->toHaveKey('void')
        ->and($offered)->not->toHaveKey('void')
        ->and($offered)->not->toHaveKey('applied')
        // SW-240 closed the rest of this control: the draft Select had been the SECOND door to
        // `issued`, needing only `credit_notes.edit` where the Issue act demands
        // `credit_notes.issue` — so the final line here flipped from asserting the picker works
        // to asserting it is a display. Issuing a saved draft is the act, and
        // `AMoneyStateMovesThroughAnActTest` drives it.
        ->and(creditNoteStatusIsEditable($note))->toBeFalse();
});

it('closes the picker once the note stops being a draft', function () {
    // Every transition after draft belongs to an act. The field stays visible so the operator can
    // read the status, and is not submitted — which is also what keeps an applied or voided note
    // saveable at all.
    $issued = noteWorth(5000, 'issued');

    expect(creditNoteStatusIsEditable($issued))->toBeFalse();

    app(CreditNoteService::class)->void($issued->fresh(), 'Raised in error');

    expect(creditNoteStatusIsEditable($issued->fresh()))->toBeFalse();
});

it('refuses a void that did not come from the Void act', function () {
    $note = noteWorth(5000, 'issued');

    // Exactly what a crafted Livewire payload does: the status alone.
    expect(fn () => $note->update(['status' => 'void']))->toThrow(DomainException::class);

    expect($note->fresh()->status)->toBe('issued');
});

it('refuses to void a note whose credit has already been spent, on every path', function () {
    $note = noteWorth(5000, 'issued');
    $note->forceFill(['applied_amount' => 5000, 'balance' => 0])->saveQuietly();

    // Even carrying the stamp the real act would set — the MONEY rule holds regardless of provenance,
    // because voiding a spent note strands the application against a note that no longer exists.
    expect(fn () => $note->fresh()->update(['status' => 'void', 'voided_at' => now()]))
        ->toThrow(DomainException::class);

    expect($note->fresh()->status)->toBe('issued');
});

it('still lets the real Void act through — the control', function () {
    // Without this, a guard that refused every void would satisfy the refusals above and read as a
    // pass. The service is the path an operator actually takes.
    $note = noteWorth(5000, 'issued');

    app(CreditNoteService::class)->void($note->fresh(), 'Raised in error');

    expect($note->fresh()->status)->toBe('void')
        ->and($note->fresh()->voided_at)->not->toBeNull()
        ->and(round((float) $note->fresh()->balance, 2))->toEqual(0.0);
});

it('refuses to issue a note into a CLOSED accounting period, from the form as well as the service', function () {
    // The quiet half. `CreditNoteService::issue()` asserts the period is open; the status Select
    // did not, so an ordinary edit could commit the AR effect while the journal post was refused
    // inside the best-effort sync — "Saved", and the ledger never moves.
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);
    AccountingPeriod::forDate(CarbonImmutable::now())->update(['status' => 'closed']);

    $note = noteWorth(5000);

    expect(fn () => $note->update(['status' => 'issued']))->toThrow(DomainException::class)
        ->and($note->fresh()->status)->toBe('draft');
});

it('still issues into an OPEN period — the control', function () {
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);

    $note = noteWorth(5000);

    app(CreditNoteService::class)->issue($note->fresh());

    expect($note->fresh()->status)->toBe('issued');
});

/*
|--------------------------------------------------------------------------
| The two regressions the FIRST cut of this fix introduced
|--------------------------------------------------------------------------
| Removing `void` and `applied` from the options outright was worse than the bug. Filament derives
| an `in:` rule from the options whenever it cannot resolve the CURRENT state's label, and an
| unresolvable state yields `Rule::in([])` — which nothing satisfies. Both were caught in review,
| and no test in this repository could see them, because none called `save()` on a note that was not
| `issued`.
*/

it('lets an operator edit a note that is already APPLIED', function () {
    $note = noteWorth(5000, 'applied');
    $note->forceFill(['applied_amount' => 5000, 'balance' => 0])->saveQuietly();

    Livewire::test(EditCreditNote::class, ['record' => $note->fresh()->getRouteKey()])
        ->fillForm(['notes' => 'Chased with the tenant on 3 September'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($note->fresh()->notes)->toContain('3 September')
        // …and the status is untouched: the field is disabled, so it is never submitted.
        ->and($note->fresh()->status)->toBe('applied');
});

it('lets an operator edit a VOIDED note, and cannot un-void it', function () {
    $note = noteWorth(5000, 'issued');
    app(CreditNoteService::class)->void($note->fresh(), 'Raised in error');

    expect($note->fresh()->status)->toBe('void');

    Livewire::test(EditCreditNote::class, ['record' => $note->fresh()->getRouteKey()])
        ->fillForm(['notes' => 'Superseded by CN-2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($note->fresh()->notes)->toContain('Superseded')
        ->and($note->fresh()->status)->toBe('void');

    // The second blocker: with `void` and `applied` gone from the picker, `issued` was the ONLY
    // option a voided note could be saved with — so the sole route past the error above was to
    // resurrect it. The note then prints with no VOID watermark on the document the tenant files,
    // every list reads it as live, and `voided_at` stays stamped so the blank-stamp discriminator
    // is permanently satisfied for it.
    expect(fn () => $note->fresh()->update(['status' => 'issued']))->toThrow(DomainException::class);

    expect($note->fresh()->status)->toBe('void');
});

it('shows a non-draft note its own status, translated, rather than a raw token', function () {
    // The Arabic panel rendered `void` and `applied` as the raw English token, because Filament
    // falls back to the state when the option list cannot label it. The full vocabulary is kept for
    // a non-draft record for exactly this reason.
    $note = noteWorth(5000, 'issued');
    app(CreditNoteService::class)->void($note->fresh(), 'Raised in error');

    app()->setLocale('ar');

    $options = creditNoteStatusOptions($note->fresh());

    expect($options)->toHaveKey('void')
        ->and($options['void'])->toBe(__('admin.statuses.credit_note.void'))
        ->and($options['void'])->not->toBe('void');

    app()->setLocale('en');
});

it('still lets a DRAFT note be issued from the form — the control', function () {
    // The one state where picking a status means anything, and it must keep working.
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);

    $note = noteWorth(5000);

    $offered = creditNoteStatusOptions($note);

    expect($offered)->toHaveKey('issued')
        ->and($offered)->not->toHaveKey('void')
        ->and($offered)->not->toHaveKey('applied');
});
