<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Support\FieldHelp;
use App\Support\LeaseTerm;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * When a lease's term and its dates disagree, the form SAYS SO.
 *
 * Reported from the panel with the screenshot that makes it undeniable: Create Lease → Term tab,
 * **commencement 10 Sep 2026, term 1 month, expiry 1 Oct 2028** — accepted in silence, under a
 * helper text reading *"Derived from the commencement date and the term."* `term_months` is logged
 * on the lease, copied by every renewal and read by the option-exercise service, so the
 * contradiction travels into the next contract.
 *
 * **The term is deliberately NOT forced to follow, and the rejected fix is the more useful half of
 * this file.** Flooring it to the whole months the range covers (`LeaseTerm::monthsSpanning()`)
 * looked like the honest descriptor and broke four things at once, each verified before the
 * approach was abandoned:
 *
 *   - a range SHORTER than a month still returns null, so the reported defect survived verbatim
 *     for a ten-day pop-up let — the fix would not have fixed the card;
 *   - a fifteen-year lease derived 173 against the field's own `maxValue(120)`, turning a wrong
 *     save into a dead end, refused on a number the operator never typed;
 *   - `LeaseImporter::afterValidate()` defines agreement as strict equality, so the form would
 *     have written pairs its own importer refuses when the same lease is exported and re-imported;
 *   - `DerivedDateFieldsTest` pins the leave-it-alone behaviour deliberately, and a bespoke end
 *     date — aligned to a mall's financial year, or to another tenant's fit-out — is a real thing
 *     this market signs. That is why the override exists at all.
 *
 * So the contract date stands, the term stands, and what was actually missing is that **nobody was
 * told**. Yardi treats the lease dates as the contract and derives the term from them; keeping both
 * editable is the deviation, and a visible warning is what makes it safe.
 *
 * A WARNING rather than a refusal for the same reason the importer refuses and this does not: a CSV
 * cannot be asked which of the two numbers is wrong, and an operator can.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'TRM']);
});

/** The tester's own numbers. */
function contradictoryTerm(): array
{
    return ['commencement_date' => '2026-09-10', 'term_months' => 1, 'expiry_date' => '2028-10-01'];
}

it('warns when the term and the dates describe different tenancies — the tester s exact case', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm(contradictoryTerm())
            // Names the derived date, so the operator can see WHICH field is wrong rather than
            // being told only that something is.
            ->assertSee(__('admin.helpers.expiry_date_mismatch', [
                'months' => 1,
                'derived' => '2026-10-09',
            ]))
            // …and nothing was silently restated: the negotiated end date and the typed term both
            // stand exactly as entered.
            ->assertFormSet(['term_months' => 1, 'expiry_date' => '2028-10-01']);
    });
});

it('stays quiet when the term and the dates agree', function () {
    // The control that decides whether this is a warning or noise. A note shown on a correct form
    // is read as an error, and then ignored on the form where it matters.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm(['commencement_date' => '2026-01-01', 'term_months' => 12])
            ->assertFormSet(['expiry_date' => '2026-12-31'])
            ->assertDontSee(__('admin.helpers.expiry_date_mismatch', ['months' => 12, 'derived' => '2026-12-31']));
    });
});

it('still sets the term exactly when an override lands on a whole month', function () {
    // The inverse derivation is untouched, and this is the case it exists for.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm(['commencement_date' => '2026-01-01', 'term_months' => 36])
            ->fillForm(['expiry_date' => '2026-12-31'])
            ->assertFormSet(['term_months' => 12, 'expiry_date' => '2026-12-31']);
    });
});

it('does not destroy a negotiated expiry when the term is blurred without changing', function () {
    // Found by review, and the warning is what made a latent hazard likely: it points the operator
    // straight at the term field. Livewire's blur modifier commits unconditionally and Filament
    // then calls `afterStateUpdated` whether or not the value moved — so merely clicking in to READ
    // the term and tabbing out used to re-derive the expiry over the date they had just negotiated.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm(contradictoryTerm())
            // Re-set the SAME value: the shape a blur produces.
            ->set('data.term_months', 1)
            ->assertFormSet(['expiry_date' => '2028-10-01']);
    });
});

it('still derives the expiry when the term really changes', function () {
    // The paired control: skipping the no-op blur must not skip a real edit.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm(contradictoryTerm())
            ->set('data.term_months', 24)
            ->assertFormSet(['expiry_date' => '2028-09-09']);
    });
});

it('words the warning in both languages, within the help budget', function () {
    expect(Lang::has('admin.helpers.expiry_date_mismatch', 'en', false))->toBeTrue()
        ->and(Lang::has('admin.helpers.expiry_date_mismatch', 'ar', false))->toBeTrue()
        ->and((bool) preg_match('/\p{Arabic}/u', __('admin.helpers.expiry_date_mismatch', [], 'ar')))->toBeTrue()
        ->and(str_word_count(__('admin.helpers.expiry_date_mismatch', [], 'en')))
        ->toBeLessThanOrEqual(FieldHelp::WORD_BUDGET);
});

it('keeps the importer strict, because a CSV has nobody to ask', function () {
    // The form warns and lets the operator decide; the importer refuses the identical pair. That is
    // not two rules — it is one rule with and without somebody present to answer it.
    expect(LeaseTerm::expiryFrom('2026-09-10', 1))->toBe('2026-10-09')
        ->and(LeaseTerm::monthsBetween('2026-09-10', '2028-10-01'))->toBeNull()
        ->and(LeaseTerm::monthsBetween('2026-01-01', '2026-12-31'))->toBe(12)
        // The normaliser the form compares through — a record-filled form carries a Carbon here,
        // a create form a string, and a comparison handling one would be wrong on the other.
        ->and(LeaseTerm::asDateString(new DateTimeImmutable('2026-10-09 13:45:00')))->toBe('2026-10-09')
        ->and(LeaseTerm::asDateString(''))->toBeNull();
});
