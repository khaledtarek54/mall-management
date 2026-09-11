<?php

use App\Filament\Admin\Pages\PropertyOverrides;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\PostDatedCheque;
use App\Models\Unit;
use App\Notifications\ReservationLapsedNotification;
use App\Services\ActivateLeaseService;
use App\Services\LeaseCreationService;
use App\Support\DepositBasis;
use App\Support\LeaseActivation;
use App\Support\LeaseEventNarrative;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\LockSpy;

/**
 * **A lease is ACTIVATED by an act, once the money is in — and the reservation lapses when it
 * is not** — client meeting 2026-09-02, points 1 · 2 · 3.
 *
 * Until 2026-09-11 activation was a DROPDOWN (anyone with `leases.edit` picked `active` on the
 * form), nothing asked whether the deposit or the cheques had arrived, no reservation ever
 * lapsed, and the deposit could be agreed as months of rent or a fixed sum but not — as Egyptian
 * clauses commonly put it — as a percentage of the annual rent.
 *
 * The standard, and where this is stricter than it: Voyager, MRI and Entrata all put an approval
 * between entering a lease and its going live, so activation is an act with its own right
 * (`leases.activate`, accounting's — Yardi's entering-vs-executing split). A MONEY gate is Yardi's
 * residential "no move-in with a balance", a per-property control; Voyager Commercial's default is
 * no gate. So every default here is Yardi's — `none`, no reservation window, months of rent — and
 * the client's own rules are what they SET, per property. Nothing an install does moves on deploy,
 * and the first test pins exactly that.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    CarbonImmutable::setTestNow('2026-09-11');

    $this->asset = makeAsset(['code' => 'ACT']);
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A lease entered through the wizard — the door that used to create it `active` unconditionally. */
function enteredLease($ctx, array $lease = [], ?Unit $unit = null): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $ctx->tenant->id,
        'lease' => array_merge([
            'unit_id' => ($unit ?? $ctx->unit)->id,
            'commencement_date' => '2026-09-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 0,
        ], $lease),
    ])->fresh();
}

function requireMoney($ctx, string $requirement = LeaseActivation::DEPOSIT, int $days = 0): void
{
    PropertySettings::set('billing.lease_activation_requires', $ctx->asset->id, $requirement);
    PropertySettings::set('billing.reservation_valid_days', $ctx->asset->id, $days);
}

function depositReceipt(Lease $lease, float $amount, string $on = '2026-09-05'): DepositTransaction
{
    return DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => $amount,
        'transaction_date' => $on, 'method' => 'bank', 'status' => 'recorded',
    ]);
}

// ── The defaults are Yardi's: nothing moves on deploy ────────────────────────────────────────────

it('still executes a lease on entry where the property requires nothing — the shipped default', function () {
    expect(LeaseActivation::requirementFor($this->asset->id))->toBe(LeaseActivation::NONE)
        ->and(LeaseActivation::reservationDaysFor($this->asset->id))->toBe(0)
        ->and(DepositBasis::defaultsFor($this->asset->id)['basis'])->toBe(DepositBasis::MONTHS);

    $lease = enteredLease($this);

    expect($lease->status)->toBe('active')
        ->and($lease->reserved_until)->toBeNull()
        // …and the wizard lease's deposit now TRACKS the rent, as a form lease's does (EG-35's own
        // rule, which the wizard had never applied — it wrote the sum and no multiple).
        ->and($lease->security_deposit_basis)->toBe(DepositBasis::MONTHS)
        ->and((float) $lease->security_deposit_months)->toBe(3.0)
        ->and((float) $lease->security_deposit)->toBe(30000.0);
});

// ── Entry no longer executes once the property asks for money ──────────────────────────────────

it('enters a lease AWAITING ACTIVATION, holding its unit, when the property requires the deposit', function () {
    requireMoney($this, LeaseActivation::DEPOSIT, days: 14);

    $lease = enteredLease($this);

    expect($lease->status)->toBe('pending_approval')
        ->and($lease->reserved_until?->toDateString())->toBe('2026-09-25')
        ->and($this->unit->fresh()->status)->toBe('reserved')
        // A pending lease does not HOLD the premises — another signer may still take the shop.
        ->and($this->unit->fresh()->isActivelyLeased())->toBeFalse();
});

it('withholds `active` from the create form where money is required, and offers it where it is not', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $options = fn (): array => array_keys(Livewire::test(CreateLease::class)
        ->instance()->form->getComponent('status')->getOptions());

    expect($options())->toContain('active');

    requireMoney($this);
    PropertySettings::forgetCache();

    expect($options())->not->toContain('active')
        ->and($options())->toContain('draft')
        ->and($options())->toContain('pending_approval');
});

// ── The act ────────────────────────────────────────────────────────────────────────────────────

it('refuses to activate until the deposit is held, in the reader\'s words, with the figures', function () {
    requireMoney($this);
    $lease = enteredLease($this);   // deposit agreed 30,000, nothing received

    expect(LeaseActivation::shortfall($lease))->toMatchArray(['required' => 30000.0, 'held' => 0.0]);

    expect(fn () => app(ActivateLeaseService::class)->activate($lease))
        ->toThrow(DomainException::class, '30,000.00');

    expect($lease->fresh()->status)->toBe('pending_approval');

    // Both languages, no raw key, the figures in the sentence.
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $sentence = LeaseActivation::explain(LeaseActivation::shortfall($lease));
        expect($sentence)->toContain('30,000.00')->not->toContain('admin.refusals');
    }
    app()->setLocale('en');
});

it('activates once the deposit is held, clears the window, holds the unit and records the event', function () {
    requireMoney($this, days: 14);
    $lease = enteredLease($this);
    depositReceipt($lease, 30000);

    expect(LeaseActivation::shortfall($lease))->toBeNull();

    $activated = app(ActivateLeaseService::class)->activate($lease, userId: null);

    expect($activated->status)->toBe('active')            // commencement 1 Sep, today 11 Sep
        ->and($activated->reserved_until)->toBeNull()
        ->and($this->unit->fresh()->status)->toBe('occupied')
        ->and($this->unit->fresh()->isActivelyLeased())->toBeTrue();

    $event = LeaseEvent::where('lease_id', $lease->id)->where('type', LeaseEvent::TYPE_ACTIVATION)->sole();
    expect($event->payload[LeaseEventNarrative::KEY])->toBe('lease_activated')
        ->and(LeaseEventNarrative::resolve($event, 'en'))->toContain('Active')->toContain('01/09/2026')
        ->and(LeaseEventNarrative::resolve($event, 'ar'))->toMatch('/\p{Arabic}/u');
});

it('takes the lease lock, then the unit lock, on the real activation path', function () {
    // `ConcurrencyPolicy::PROVEN` has claimed this since dfe49970 with no test driving `LockSpy`
    // through the service — `CriticalSectionsTakeTheirLockTest` was red on `main` for ten days
    // saying so (found 2026-09-12). Activation is the moment a pending lease starts to hold its
    // premises, so it is the fourth writer that can put two leases on one shop; the spy sees the
    // locks the sqlite suite otherwise compiles to nothing.
    requireMoney($this, days: 14);
    $lease = enteredLease($this);
    depositReceipt($lease, 30000);

    $spy = LockSpy::watch(fn () => app(ActivateLeaseService::class)->activate($lease));

    // THE ROW READ, by statement shape — `locked('leases')` alone is satisfied by the double-let
    // guard's `exists(… leases … for update)` on the same path, and stayed green with the
    // service's own `lockForUpdate()` deleted (mutation, 2026-09-12). Isolate, then mutate.
    $ownRow = collect($spy->lockedStatements('leases'))
        ->contains(fn (string $sql): bool => (bool) preg_match('/from "leases" where "leases"\."id" = \?/', $sql));

    expect($ownRow)->toBeTrue('activation must lock the lease row it re-reads, not only what the double-let guard locks')
        ->and($spy->locked('units'))->toBeTrue('activation must lock the units it is about to hold');
});

it('activates as FUTURE when the commencement is ahead — the calendar answers, not the act', function () {
    // Mutation note: the service asks `Lease::executedStatusFor()` and so does the model's own
    // `saving` derivation, so writing a literal `active` in the service leaves this green — the
    // MODEL is the guard; the service's call is the same rule stated where the decision is made.
    requireMoney($this);
    $lease = enteredLease($this, ['commencement_date' => '2026-12-01']);
    depositReceipt($lease, 30000);

    expect(app(ActivateLeaseService::class)->activate($lease)->status)->toBe('future');
});

it('accepts lodged cheques in place of the deposit only where the property says so', function () {
    requireMoney($this, LeaseActivation::DEPOSIT);
    $lease = enteredLease($this);

    // Lodged THROUGH A DOOR the panel has: the cheque register's own create page, naming the
    // lease. `post_dated_cheques.lease_id` had existed since the register shipped and no door
    // wrote it — the review found the first version of this test writing it straight into the
    // model, a fixture reaching a state no operator could, over a rule that was therefore
    // unsatisfiable on a real install.
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreatePostDatedCheque::class)
        ->fillForm([
            'asset_id' => $this->asset->id, 'tenant_id' => $this->tenant->id, 'lease_id' => $lease->id,
            'cheque_number' => 'CHQ-1', 'bank_name' => 'NBE', 'amount' => 30000,
            'cheque_date' => '2026-10-01', 'received_date' => '2026-09-05',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PostDatedCheque::where('lease_id', $lease->id)->where('status', PostDatedCheque::STATUS_HELD)->sum('amount'))->toEqual(30000);

    // Deposit required, only cheques in hand: refused…
    expect(LeaseActivation::shortfall($lease))->not->toBeNull();

    // …and accepted once the property counts cheques.
    requireMoney($this, LeaseActivation::DEPOSIT_OR_CHEQUES);
    PropertySettings::forgetCache();
    expect(LeaseActivation::shortfall($lease->fresh()))->toBeNull();

    // A bounced cheque is not security.
    PostDatedCheque::where('lease_id', $lease->id)->update(['status' => PostDatedCheque::STATUS_BOUNCED]);
    expect(LeaseActivation::shortfall($lease->fresh()))->toMatchArray(['cheques' => 0.0]);
});

it('refuses to activate onto a unit somebody else has taken meanwhile', function () {
    requireMoney($this);
    $pending = enteredLease($this);
    depositReceipt($pending, 30000);

    // Another tenant signs and is executed on the same shop while this one waits for its deposit.
    makeLease($this->unit, makeTenant(), ['status' => 'active']);

    expect(fn () => app(ActivateLeaseService::class)->activate($pending))
        ->toThrow(DomainException::class, $this->unit->code);

    expect($pending->fresh()->status)->toBe('pending_approval');
});

it('refuses to activate a lease that is not awaiting activation — an act, not a status you set twice', function () {
    $lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);

    expect(fn () => app(ActivateLeaseService::class)->activate($lease))
        ->toThrow(DomainException::class, $lease->reference);
});

// ── The worklist: the accountant's button on the row ────────────────────────────────────────────

it('offers Activate on the row to accounting and not to leasing — entering and executing are two rights', function () {
    // Mutation note: dropping the permission from `visible()` leaves this green, because
    // `->authorize()` folds into `isHidden()` on Filament v4.11.8 (the contract
    // `FilamentActionDispatchContractTest` pins) — `authorize()` is the layer doing the work here
    // and `visible()` is the doubled gate CLAUDE.md asks for, so an upstream change cannot reopen
    // it silently.
    requireMoney($this);
    $lease = enteredLease($this);
    depositReceipt($lease, 30000);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Leasing ENTERS; it does not activate.
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);
    Livewire::test(ListLeases::class)->assertTableActionHidden('activate', $lease);
    auth()->logout();
    session()->flush();

    // Accounting holds leases.view + leases.activate and NOT leases.edit — the row is its door.
    $accountant = makeUser('accounting', [$this->asset->id]);
    expect($accountant->can('leases.activate'))->toBeTrue()
        ->and($accountant->can('leases.edit'))->toBeFalse();

    $this->actingAs($accountant);
    Filament::setTenant($this->asset);
    Livewire::test(ListLeases::class)
        ->assertTableActionVisible('activate', $lease)
        ->callTableAction('activate', $lease)
        ->assertHasNoTableActionErrors();

    expect($lease->fresh()->status)->toBe('active');
});

it('shows the shortfall on the button before the click, and disables it', function () {
    requireMoney($this);
    $lease = enteredLease($this);   // nothing received

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListLeases::class)
        ->assertTableActionVisible('activate', $lease)
        ->assertTableActionDisabled('activate', $lease);
});

it('offers Activate on the lease page too — the record hub — from the one definition the row takes', function () {
    // Reported 2026-09-12: a super admin opened an awaiting lease, found no *Active* in the status
    // dropdown and no button on the page, and read it as "I cannot make the lease active". The
    // row is accounting's door (it lacks leases.edit); the page is everyone else's.
    requireMoney($this);
    $lease = enteredLease($this);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // Nothing received: the button is there, disabled, and says what is missing in figures.
    $page = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
    $page->assertActionVisible('activate')->assertActionDisabled('activate');
    $shortfall = LeaseActivation::shortfall($lease);
    expect($shortfall)->not->toBeNull();
    $page->assertSee(e(LeaseActivation::explain($shortfall)), escape: false);

    depositReceipt($lease, 30000);
    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertActionEnabled('activate')
        ->callAction('activate')
        ->assertHasNoActionErrors();

    expect($lease->fresh()->status)->toBe('active');

    // Gone once active — an act, not a status you set twice.
    Livewire::test(EditLease::class, ['record' => $lease->getKey()])->assertActionHidden('activate');
});

it('says on the status field why Active is withheld, only where the property gates it', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    // Yardi's default — nothing gates, nothing to say.
    Livewire::test(CreateLease::class)
        ->assertDontSee(__('admin.helpers.lease_activation_is_an_act'));

    requireMoney($this);
    Livewire::test(CreateLease::class)
        ->assertSee(__('admin.helpers.lease_activation_is_an_act'));

    // On the record of a lease already ACTIVE the value stays offered and the sentence is not shown.
    $active = enteredLease($this);
    depositReceipt($active, 30000);
    app(ActivateLeaseService::class)->activate($active, null);
    Livewire::test(EditLease::class, ['record' => $active->getKey()])
        ->assertDontSee(__('admin.helpers.lease_activation_is_an_act'));
});

// ── The reservation lapses ─────────────────────────────────────────────────────────────────────

it('cancels a reservation whose window ran out with the deposit still unpaid, frees the unit and tells leasing', function () {
    Notification::fake();
    requireMoney($this, days: 7);
    // The leasing agent assigned to the property is who `AssetStaffRecipients` resolves to.
    $agent = makeUser('leasing', [$this->asset->id]);
    $lease = enteredLease($this);
    expect($lease->reserved_until?->toDateString())->toBe('2026-09-18');

    CarbonImmutable::setTestNow('2026-09-19');

    $this->artisan('leases:expire')->assertSuccessful();

    expect($lease->fresh()->status)->toBe('cancelled')
        ->and($this->unit->fresh()->status)->toBe('vacant');

    $event = LeaseEvent::where('lease_id', $lease->id)->where('type', LeaseEvent::TYPE_CANCELLATION)->sole();
    expect(LeaseEventNarrative::resolve($event, 'en'))->toContain('18/09/2026');

    // The leasing team is told, once, with the deep link's record id in the payload.
    Notification::assertSentTo($agent, ReservationLapsedNotification::class, function (ReservationLapsedNotification $n) use ($lease, $agent): bool {
        $payload = $n->toDatabase($agent);

        return $payload['lease_id'] === $lease->id && str_contains($payload['body'], '2026-09-18');
    });
    Notification::assertSentTimes(ReservationLapsedNotification::class, 1);

    // …and a second run finds nothing to do.
    $this->artisan('leases:expire')->assertSuccessful();
    expect(LeaseEvent::where('lease_id', $lease->id)->count())->toBe(1);
});

it('leaves alone a lapsed reservation whose money HAS arrived, one under an issued invoice, and one the property does not gate', function () {
    Notification::fake();
    requireMoney($this, days: 7);
    CarbonImmutable::setTestNow('2026-09-10');

    $paid = enteredLease($this);
    depositReceipt($paid, 30000, '2026-09-10');

    $billed = enteredLease($this, [], makeUnit($this->asset));
    makeInvoice($billed, ['asset_id' => $this->asset->id, 'status' => 'issued', 'total' => 30000, 'balance' => 30000]);

    // On a property that gates NOTHING, a pending lease with a window (entered as a draft through
    // the form, say) is a reminder, not a cancellation waiting to happen.
    $ungated = makeLease(makeUnit(makeAsset(['code' => 'FREE'])), makeTenant(), [
        'status' => 'pending_approval', 'reserved_until' => '2026-09-01',
    ]);

    CarbonImmutable::setTestNow('2026-09-30');
    $this->artisan('leases:expire')->assertSuccessful();

    expect($paid->fresh()->status)->toBe('pending_approval')     // the accountant's to activate
        ->and($billed->fresh()->status)->toBe('pending_approval') // a person's call under a live document
        ->and($ungated->fresh()->status)->toBe('pending_approval'); // no money to wait for

    Notification::assertNothingSent();
});

// ── The deposit basis ──────────────────────────────────────────────────────────────────────────

it('derives a deposit agreed as a percentage of the annual rent, and re-derives it when the rent moves', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'base_rent_monthly' => 10000,
        'security_deposit_basis' => DepositBasis::PERCENT_OF_ANNUAL_RENT,
        'security_deposit_percent' => 10,
        'security_deposit_months' => null,
    ]);

    expect((float) $lease->fresh()->security_deposit)->toBe(12000.0);   // 10% × 120,000

    $lease->update(['base_rent_monthly' => 20000]);
    expect((float) $lease->fresh()->security_deposit)->toBe(24000.0);

    // A FIXED deposit never moves, whatever the rent does.
    $flat = makeLease(makeUnit($this->asset), makeTenant(), [
        'base_rent_monthly' => 10000, 'security_deposit_basis' => DepositBasis::FIXED,
        'security_deposit_months' => null, 'security_deposit' => 7777,
    ]);
    $flat->update(['base_rent_monthly' => 50000]);
    expect((float) $flat->fresh()->security_deposit)->toBe(7777.0);
});

it('proposes the property\'s own basis on a lease entered through the wizard', function () {
    PropertySettings::set('billing.default_security_deposit_basis', $this->asset->id, DepositBasis::PERCENT_OF_ANNUAL_RENT);
    PropertySettings::set('billing.default_security_deposit_percent', $this->asset->id, 8);

    $lease = enteredLease($this);

    expect($lease->security_deposit_basis)->toBe(DepositBasis::PERCENT_OF_ANNUAL_RENT)
        ->and((float) $lease->security_deposit_percent)->toBe(8.0)
        ->and($lease->security_deposit_months)->toBeNull()
        ->and((float) $lease->security_deposit)->toBe(9600.0);   // 8% × 120,000

    // An agreed figure still wins — as a FIXED basis.
    $agreed = enteredLease($this, ['security_deposit' => 5000], makeUnit($this->asset));
    expect($agreed->security_deposit_basis)->toBe(DepositBasis::FIXED)
        ->and((float) $agreed->security_deposit)->toBe(5000.0);
});

it('reads a lease written with a multiple and no basis as months, and one with neither as fixed', function () {
    // Every writer before the basis existed, and the importer still: the backfill's rule, on the
    // way in.
    $months = Lease::forceCreate([...makeLease($this->unit, $this->tenant)->only(['unit_id', 'tenant_id', 'status', 'commencement_date', 'expiry_date', 'term_months', 'currency']), 'base_rent_monthly' => 1000, 'security_deposit_months' => 2, 'security_deposit_basis' => null]);
    expect($months->fresh()->security_deposit_basis)->toBe(DepositBasis::MONTHS)
        ->and((float) $months->fresh()->security_deposit)->toBe(2000.0);
});

// ── Configurable, the way the market is ────────────────────────────────────────────────────────

it('offers every one of the four settings per property, with the vocabulary the Settings page uses', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $html = Livewire::test(PropertyOverrides::class)->assertOk()->html();

    foreach (['lease_activation_requires', 'reservation_valid_days', 'default_security_deposit_basis', 'default_security_deposit_percent'] as $key) {
        expect($html)->toContain(__("admin.settings.fields.{$key}"));
    }

    // The two choice settings render their words, not a number box. The raw-key sweep is over the
    // VISIBLE text: Livewire's snapshot attribute carries option keys as data.
    $visible = html_entity_decode(strip_tags(preg_replace('/wire:snapshot="[^"]*"/', '', $html)));
    expect($html)->toContain(__('admin.lease_activation.deposit_or_cheques'))
        ->and($html)->toContain(__('admin.deposit_basis.percent_of_annual_rent'))
        ->and($visible)->not->toMatch('/admin\.[a-z_]+\.[a-z_.]+/');
});

it('fills a cheque\'s lease from the invoice it is lodged against, and refuses another tenant\'s lease', function () {
    requireMoney($this, LeaseActivation::DEPOSIT_OR_CHEQUES);
    $lease = enteredLease($this);
    $deposit = makeInvoice($lease, ['asset_id' => $this->asset->id, 'status' => 'issued', 'total' => 30000, 'balance' => 30000]);

    // The commonest door: a cheque against the deposit INVOICE, no lease named — derived.
    $cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(), 'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id, 'invoice_id' => $deposit->id, 'cheque_number' => 'CHQ-2',
        'bank_name' => 'NBE', 'amount' => 30000, 'cheque_date' => '2026-10-01', 'received_date' => '2026-09-05',
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    expect($cheque->fresh()->lease_id)->toBe($lease->id)
        ->and(LeaseActivation::shortfall($lease->fresh()))->toBeNull();

    // A crafted payload naming another tenant's lease is refused in words.
    $other = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    expect(fn () => PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(), 'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id, 'lease_id' => $other->id, 'cheque_number' => 'CHQ-3',
        'bank_name' => 'NBE', 'amount' => 100, 'cheque_date' => '2026-10-01', 'received_date' => '2026-09-05',
        'status' => PostDatedCheque::STATUS_HELD,
    ]))->toThrow(DomainException::class, __('admin.refusals.cheque_lease_other_tenant'));
});

it('stores a per-property CHOICE as the word it is, not as 0 — the override page round-trips', function () {
    // The blocker the review found: `PropertyOverrides::save()` cast every value to float, so
    // picking `deposit_received` stored 0, read back as `none`, and the gate stayed off on the
    // mall everybody believed it was on. `billing.proration_method` had been dead the same way.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(PropertyOverrides::class)
        ->fillForm([
            'billing__lease_activation_requires' => LeaseActivation::DEPOSIT_OR_CHEQUES,
            'billing__default_security_deposit_basis' => DepositBasis::PERCENT_OF_ANNUAL_RENT,
            'billing__reservation_valid_days' => '14',
            'billing__proration_method' => 'thirty_day',
            'billing__auto_apply_tenant_credit' => '0',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    PropertySettings::forgetCache();

    expect(LeaseActivation::requirementFor($this->asset->id))->toBe(LeaseActivation::DEPOSIT_OR_CHEQUES)
        ->and(PropertySettings::get('billing.default_security_deposit_basis', $this->asset->id))->toBe(DepositBasis::PERCENT_OF_ANNUAL_RENT)
        ->and(PropertySettings::get('billing.proration_method', $this->asset->id))->toBe('thirty_day')
        ->and(PropertySettings::get('billing.reservation_valid_days', $this->asset->id))->toEqual(14)
        ->and(PropertySettings::get('billing.auto_apply_tenant_credit', $this->asset->id))->toBeFalse();
});

it('requires the figure its basis needs, and proposes the property\'s percent on the create form', function () {
    PropertySettings::set('billing.default_security_deposit_basis', $this->asset->id, DepositBasis::PERCENT_OF_ANNUAL_RENT);
    PropertySettings::set('billing.default_security_deposit_percent', $this->asset->id, 10);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $page = Livewire::test(CreateLease::class);

    // The form OPENS on the property's basis and percent — the create form is the door EG-35's own
    // finding says gets forgotten.
    expect($page->get('data.security_deposit_basis'))->toBe(DepositBasis::PERCENT_OF_ANNUAL_RENT)
        ->and((float) $page->get('data.security_deposit_percent'))->toBe(10.0);

    // …and a percent basis with the percent blanked is REFUSED, not stored as whatever the amount
    // last read.
    $page->fillForm([
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id, 'status' => 'draft',
        'commencement_date' => '2026-10-01', 'term_months' => 12, 'base_rent_monthly' => 10000,
        'security_deposit_basis' => DepositBasis::PERCENT_OF_ANNUAL_RENT, 'security_deposit_percent' => null,
    ])->call('create')->assertHasFormErrors(['security_deposit_percent']);
});

it('falls back to months when the property\'s percent basis carries no percent', function () {
    PropertySettings::set('billing.default_security_deposit_basis', $this->asset->id, DepositBasis::PERCENT_OF_ANNUAL_RENT);
    // percent left at the shipped 0

    expect(DepositBasis::defaultsFor($this->asset->id)['basis'])->toBe(DepositBasis::MONTHS);

    requireMoney($this);
    $lease = enteredLease($this);

    // Not a 0.00 deposit that walks through the gate.
    expect((float) $lease->security_deposit)->toBe(30000.0)
        ->and(LeaseActivation::shortfall($lease))->not->toBeNull();
});

it('gives the leases tab its own words and leaves the procurement board\'s alone', function () {
    // `admin.tabs.pending_approval` is shared with purchase requests; relabelling it for leases
    // renamed the procurement board's tab too (found by review).
    foreach (['en', 'ar'] as $locale) {
        expect(__('admin.tabs.awaiting_activation', [], $locale))->not->toBe(__('admin.tabs.pending_approval', [], $locale))
            ->and(__('admin.tabs.awaiting_activation', [], $locale))->not->toContain('admin.tabs');
    }
    expect(__('admin.tabs.pending_approval', [], 'en'))->toBe('Pending approval');
});

it('clears the reservation window on every exit from awaiting, and a renewal does not inherit it', function () {
    requireMoney($this, days: 14);
    $lease = enteredLease($this);
    expect($lease->reserved_until)->not->toBeNull();

    // Out through a door that is not the act — the dropdown on a property switched back to none.
    requireMoney($this, LeaseActivation::NONE, days: 14);
    PropertySettings::forgetCache();
    $lease->fresh()->update(['status' => 'active']);
    expect($lease->fresh()->reserved_until)->toBeNull();

    // A renewal of a lease that still carries a stamp (a stamped row edited outside the model) does
    // not carry the hold forward: `RENEWAL_RESETS` names it.
    expect(Lease::RENEWAL_RESETS)->toHaveKey('reserved_until');
});

it('never lapses a DRAFT — terms still being written are not a reservation the sweep may cancel', function () {
    Notification::fake();
    requireMoney($this, days: 7);

    // A draft under negotiation with a real deposit owed and nothing received, under a LIVE
    // policy. It carries NO window — a draft is not a reservation, and the model clears any date
    // a door tries to stamp on one — and the sweep never reads it. The control beside it: a
    // pending lease in the same money state lapses.
    $draft = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'draft', 'base_rent_monthly' => 10000, 'security_deposit_months' => 3, 'reserved_until' => '2026-09-01',
    ]);
    expect($draft->fresh()->reserved_until)->toBeNull()
        ->and((float) $draft->fresh()->security_deposit)->toBe(30000.0);

    $pending = enteredLease($this);
    expect($pending->reserved_until?->toDateString())->toBe('2026-09-18');

    CarbonImmutable::setTestNow('2026-09-30');
    $this->artisan('leases:expire')->assertSuccessful();

    expect($draft->fresh()->status)->toBe('draft')
        ->and($pending->fresh()->status)->toBe('cancelled');
});

it('stops lapsing stamped windows once the property sets the window back to 0', function () {
    Notification::fake();
    requireMoney($this, days: 7);

    $stamped = enteredLease($this);
    expect($stamped->reserved_until?->toDateString())->toBe('2026-09-18');

    // The policy switched off AFTER the stamp: "0 = never" has to mean never, or the column that
    // shows the date hides exactly when the sweep still reads it.
    requireMoney($this, days: 0);
    PropertySettings::forgetCache();

    CarbonImmutable::setTestNow('2026-09-30');
    $this->artisan('leases:expire')->assertSuccessful();

    expect($stamped->fresh()->status)->toBe('pending_approval');
    Notification::assertNothingSent();
});
