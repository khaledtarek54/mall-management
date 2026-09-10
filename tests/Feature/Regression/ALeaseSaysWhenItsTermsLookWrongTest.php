<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Lease;
use App\Support\FieldHelp;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two terms that are legal, surprising, and were silent — so the lease says them out loud.
 *
 * Both came from the tester as QUESTIONS rather than bug reports (Trello M6scQfGu, pqvmFQa9),
 * asking whether the system should enforce a rule. It should not, in either case, and the reasoning
 * is the deliverable:
 *
 * **Rent commencing before possession.** The date rent starts DRIVES billing — `firstBillableMonth()`
 * opens there and `graceAbates()` decides what the fit-out grace covers — while `possession_date` is
 * computed on by NOTHING; it is a recorded fact about when the keys changed hands. So the wrong
 * order costs no money and breaks no rule. It is also a real thing an operator needs to record: a
 * landlord who handed over LATE has rent running from a day the tenant could not trade, and that
 * fact is the basis of the relief claim that follows. Refusing it would leave the system unable to
 * describe the dispute. Yardi does not enforce the order either — its lease-administration model
 * calls rent commencement *usually* after possession, which describes the ordinary deal rather than
 * constraining the record.
 *
 * **A deposit longer than the term.** Twenty-four months' security on a twelve-month lease is
 * unusual and entirely real — a weak covenant, a new foreign brand, a first-time operator — and
 * neither Yardi nor MRI constrains the deposit against the term. Nothing downstream misbehaves: the
 * months are a multiplier on the rent and `security_deposit` is the sum actually held.
 *
 * Both are far more often a typo, so both are WARNED. Same answer as the escalation collar and the
 * term-vs-expiry pair on the same board: show the truth, do not forbid the value.
 *
 * **Half of this file is about staying QUIET**, and that is not padding: a note on a correct form is
 * read as an error, and then ignored on the form where it matters.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'WRN']);
});

it('says when rent starts before the tenant took possession — on the ORDINARY lease', function () {
    // `rent_commencement_date` is nullable and BLANK is the normal state: it means no fit-out
    // grace, so billing opens at the lease's own commencement. Reading only the grace field left
    // the note silent on most of the book — the demo seeder leaves it null on three leases in four
    // and says in writing that this "is the normal case" — while firing on the one spelling where
    // an operator had set it equal to commencement. Same situation, written two ways.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'commencement_date' => '2026-03-01',
                'rent_commencement_date' => null,
                'possession_date' => '2026-03-15',
            ])
            ->assertSee(__('admin.helpers.rent_starts_before_possession', ['date' => '15/03/2026']));
    });
});

it('says it when a fit-out grace IS recorded and still starts too early', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'commencement_date' => '2026-03-01',
                'rent_commencement_date' => '2026-03-01',
                'possession_date' => '2026-03-15',
            ])
            ->assertSee(__('admin.helpers.rent_starts_before_possession', ['date' => '15/03/2026']));
    });
});

it('stays quiet on the ordinary sequence — keys first, rent after', function () {
    // The control, and the shape of every normal fit-out: possession in March, rent from April.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'commencement_date' => '2026-03-01',
                'possession_date' => '2026-03-01',
                'rent_commencement_date' => '2026-04-01',
            ])
            ->assertDontSee(__('admin.helpers.rent_starts_before_possession', ['date' => '01/03/2026']));
    });
});

it('stays quiet when the keys and the rent land on the SAME day', function () {
    // The boundary. Handover and rent commencement on one date is a lease with no fit-out at all —
    // ordinary, and not something to caution anybody about.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'commencement_date' => '2026-03-01',
                'rent_commencement_date' => null,
                'possession_date' => '2026-03-01',
            ])
            ->assertDontSee(__('admin.helpers.rent_starts_before_possession', ['date' => '01/03/2026']));
    });
});

it('says when the deposit runs longer than the term it secures', function () {
    // The tester's numbers: 24 months of deposit against a 12-month term. Quoted as the SUM,
    // because that is the figure the tenant is actually asked to hand over.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 12,
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 24,
            ])
            ->assertSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '12',
                'amount' => 'EGP 240,000.00',
            ]));
    });
});

it('stays quiet on an ordinary three-month deposit', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 12,
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 3,
            ])
            ->assertDontSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '12',
                'amount' => 'EGP 30,000.00',
            ]));
    });
});

it('stays quiet on a short let, where more months than the term is the ordinary covenant', function () {
    // A kiosk or a seasonal pop-up taken for one month against the house default of three months'
    // security exceeds its term by three times over — and the operator typed nothing. Exceeding the
    // term is not on its own remarkable; more than a YEAR's rent held is.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 1,
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 3,
            ])
            ->assertDontSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '1',
                'amount' => 'EGP 30,000.00',
            ]));

        // …and the line itself: exactly a year is still quiet, above it speaks.
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 6,
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 12,
            ])
            ->assertDontSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '6',
                'amount' => 'EGP 120,000.00',
            ]));
    });
});

it('stays quiet when the deposit exactly equals the term', function () {
    // The other boundary. Eighteen months against an eighteen-month term is above the twelve-month
    // line, so only the term clause can hold it — which is what makes this a tooth of its own.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 18,
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 18,
            ])
            ->assertDontSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '18',
                'amount' => 'EGP 180,000.00',
            ]));
    });
});

it('says nothing at all until the sum is known', function () {
    // `security_deposit` is DERIVED from the rent, so on a create form where no rent has been typed
    // it is still 0 — and this repo has already recorded what a warning naming zero money reads as:
    // not a caution, a broken field. The operator here has touched neither the deposit nor the rent.
    asTenant($this->asset, function () {
        Livewire::test(CreateLease::class)
            ->fillForm([
                'term_months' => 12,
                'security_deposit_months' => 24,
            ])
            ->assertDontSee(__('admin.helpers.deposit_longer_than_term', [
                'term' => '12',
                'amount' => 'EGP 0.00',
            ]));
    });
});

it('still SAVES both, because neither is a rule violation', function () {
    // The decision itself, asserted THROUGH THE FORM so it cannot drift into a refusal later. A
    // warning that quietly became a block would take away the late-handover record and the
    // high-security deposit, both of which are real commercial arrangements — and asserting it on
    // the model would prove nothing, since the model never had a rule about either pair and a
    // refusal added to the FORM tomorrow would leave that assertion green.
    asTenant($this->asset, function () {
        $unit = makeUnit($this->asset, ['code' => 'WRN-01', 'status' => 'vacant']);
        $tenant = makeTenant();

        Livewire::test(CreateLease::class)
            ->fillForm([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'status' => 'active',
                'commencement_date' => '2026-03-01',
                'expiry_date' => '2027-02-28',
                'term_months' => 12,
                'possession_date' => '2026-03-15',
                'rent_commencement_date' => '2026-03-01',
                'base_rent_monthly' => 10000,
                'security_deposit_months' => 24,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lease = Lease::where('tenant_id', $tenant->id)->sole();

        expect($lease->possession_date->toDateString())->toBe('2026-03-15')
            ->and($lease->rent_commencement_date->toDateString())->toBe('2026-03-01')
            // …and the derived sum is the months times the rent, untouched by the warning.
            ->and((float) $lease->security_deposit)->toBe(240000.0);
    });
});

it('paints the warning colour only when it is warning about something', function () {
    // `hintColor` also colours the field's QUESTION-MARK icon — `HasHint::setUpHint()` builds that
    // icon with `->color(fn () => $parentComponent->getHintColor())` — so an unconditional amber
    // marks the informational hint on every ordinary lease as though something were wrong. Both
    // fields carry such an icon, and the three older warning hints in this form carry none, which
    // is why the file's own precedent never showed it.
    //
    // Asserted on the built component rather than on markup: the colour and the text now read ONE
    // predicate each, and this is what proves they cannot disagree.
    $hintOn = function (string $field, array $state): array {
        $page = Livewire::test(CreateLease::class)->fillForm($state);
        $component = collect($page->instance()->getSchema('form')->getFlatComponents(withHidden: true))
            ->first(fn ($c) => method_exists($c, 'getName') && $c->getName() === $field);

        return ['hint' => $component?->getHint(), 'color' => $component?->getHintColor()];
    };

    asTenant($this->asset, function () use ($hintOn) {
        // The ordinary lease: keys in March, rent from April, three months' deposit.
        $quiet = $hintOn('rent_commencement_date', [
            'commencement_date' => '2026-03-01',
            'possession_date' => '2026-03-01',
            'rent_commencement_date' => '2026-04-01',
        ]);

        expect($quiet['hint'])->toBeNull()
            ->and($quiet['color'])->toBeNull();

        // …and it really does turn amber when there is something to say, or the assertion above
        // passes just as happily on a hint that is never coloured at all.
        $loud = $hintOn('rent_commencement_date', [
            'commencement_date' => '2026-03-01',
            'rent_commencement_date' => null,
            'possession_date' => '2026-03-15',
        ]);

        expect($loud['hint'])->not->toBeNull()
            ->and($loud['color'])->toBe('warning');
    });
});

it('asks the browser for the possession date as it is typed', function () {
    // The note is computed from `possession_date`, and Filament binds a field DEFERRED unless it
    // says otherwise — `getStateBindingModifiers()` falls through to the container, which is not
    // live. So without this the browser never round-trips while possession is the field being
    // typed, and the note is invisible in exactly the direction it exists for: recording a LATE
    // handover onto a lease that already carries its rent dates.
    //
    // Asserted on the COMPONENT because nothing else here can see it. `fillForm()` fires the update
    // hook for every key it sets — the round-trip a live field would have caused — so under this
    // harness every field behaves as though it were live, and removing `->live()` leaves every
    // other case in this file green. The defect lives in the rendered binding, not in the state.
    asTenant($this->asset, function () {
        $fields = collect(Livewire::test(CreateLease::class)->instance()
            ->getSchema('form')->getFlatComponents(withHidden: true))
            ->filter(fn ($c) => method_exists($c, 'getName'))
            ->keyBy(fn ($c) => $c->getName());

        expect($fields->has('possession_date'))->toBeTrue()
            ->and($fields['possession_date']->isLive())->toBeTrue()
            // The two it is compared against were already live; named here so a future edit that
            // quietens one of them fails against the note that depends on it.
            ->and($fields['commencement_date']->isLive())->toBeTrue()
            ->and($fields['rent_commencement_date']->isLive())->toBeTrue()
            ->and($fields['security_deposit_months']->isLive())->toBeTrue()
            ->and($fields['term_months']->isLive())->toBeTrue();
    });
});

it('words both notes in EN and AR, within the help budget', function () {
    foreach ([
        'admin.helpers.rent_starts_before_possession',
        'admin.helpers.deposit_longer_than_term',
    ] as $key) {
        expect(Lang::has($key, 'en', false))->toBeTrue($key)
            ->and(Lang::has($key, 'ar', false))->toBeTrue($key)
            ->and((bool) preg_match('/\p{Arabic}/u', __($key, [], 'ar')))->toBeTrue($key)
            ->and(str_word_count(__($key, [], 'en')))->toBeLessThanOrEqual(FieldHelp::WORD_BUDGET);
    }
});
