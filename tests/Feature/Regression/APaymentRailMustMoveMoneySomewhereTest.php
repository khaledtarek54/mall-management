<?php

use App\Filament\Admin\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Models\PaymentMethod;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

/**
 * **A payment rail has to move money in some direction.**
 *
 * Both toggles default true, so this state is only reachable by deliberately un-ticking both — and
 * the result was silent in the worst way. `inboundCodes()` and `outboundCodes()` each filter on
 * their own flag, `optionsFor()` picks one of them per column, and `ValueSets` widens a column from
 * the same two readers: so a both-off rail was offered on NONE of the seven money columns, the
 * saving listener would refuse the code even if a crafted payload sent it, and the register beside
 * it went on rendering **Active** — the one word that says the opposite.
 *
 * Retiring a rail is `is_active`, and the two are not the same act: an inactive rail is still
 * LABELLED on the documents that name it and still found by `filterOptionsFor()`, which is what
 * keeps those documents readable. Both directions off is not a retirement; it is a row that means
 * nothing.
 *
 * Measured on `mall_management_qa` 2026-09-04: 0 of 11 rails have both directions off, so the guard
 * locks nothing out — and the last two cases below are the proof that it cannot.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a rail with both directions off, on the field the operator un-ticked', function () {
    Livewire::test(CreatePaymentMethod::class)
        ->fillForm([
            'code' => 'inert_rail',
            'name_en' => 'Inert rail',
            'name_ar' => 'قناة خاملة',
            'for_inbound' => false,
            'for_outbound' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['for_inbound', 'for_outbound']);

    expect(PaymentMethod::query()->where('code', 'inert_rail')->exists())->toBeFalse();
});

it('saves the moment one direction is on — the control', function () {
    // A guard that refused everything would satisfy the case above and read as a pass. A collection
    // network is inbound-only and is the ordinary Egyptian rail this catalogue exists for.
    Livewire::test(CreatePaymentMethod::class)
        ->fillForm([
            'code' => 'collection_only',
            'name_en' => 'Collection network',
            'name_ar' => 'شبكة تحصيل',
            'for_inbound' => true,
            'for_outbound' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PaymentMethod::query()->where('code', 'collection_only')->exists())->toBeTrue();
});

it('refuses it off the panel too, where no form rule can reach', function () {
    // The seeder, a console command and a future importer all write through the model, and the form
    // rule is a courtesy rather than the gate.
    expect(fn () => PaymentMethod::create([
        'code' => 'inert_by_code',
        'name_en' => 'Inert by code',
        'name_ar' => 'خاملة برمجيًا',
        'for_inbound' => false,
        'for_outbound' => false,
    ]))->toThrow(DomainException::class);

    expect(PaymentMethod::query()->where('code', 'inert_by_code')->exists())->toBeFalse();
});

it('does not lock an already-inert row out of being edited or repaired', function () {
    // The `#[NeverDeletable]` trap: a guard on every save turns a broken row into a dead end. This
    // row cannot be made through the model any more, so it is written the way a legacy install or a
    // data fix would have left one.
    DB::table('payment_methods')->insert([
        'code' => 'legacy_inert',
        'name_en' => 'Legacy inert',
        'name_ar' => 'قناة قديمة خاملة',
        'ledger_account_id' => null,
        'for_inbound' => false,
        'for_outbound' => false,
        'requires_bank_account' => true,
        'settlement_days' => 0,
        'is_active' => true,
        'sort_order' => 0,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rail = PaymentMethod::query()->where('code', 'legacy_inert')->firstOrFail();

    // Editing something else still works…
    $rail->update(['name_en' => 'Renamed while still inert']);
    expect($rail->fresh()->name_en)->toBe('Renamed while still inert');

    // …and the way OUT of the state passes the guard rather than tripping it.
    $rail->update(['for_outbound' => true]);
    expect($rail->fresh()->for_outbound)->toBeTrue();
});

it('says so in both languages, and names the escape', function () {
    // `Lang::has()` falls back to English unless told not to, so the Arabic half needs fallback:false
    // AND a check that Arabic script actually landed in the Arabic key.
    expect(Lang::has('admin.refusals.payment_method_moves_no_money', 'en', false))->toBeTrue()
        ->and(Lang::has('admin.refusals.payment_method_moves_no_money', 'ar', false))->toBeTrue();

    $ar = (string) __('admin.refusals.payment_method_moves_no_money', [], 'ar');
    $en = (string) __('admin.refusals.payment_method_moves_no_money', [], 'en');

    expect(preg_match('/[\x{0600}-\x{06FF}]/u', $ar))->toBe(1)
        // A refusal with no way out is worse than the bug: both sentences name `is_active` as the
        // act the operator probably meant.
        ->and($en)->toContain('Active')
        ->and($ar)->toContain('نشط');
});
