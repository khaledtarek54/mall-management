<?php

use App\Filament\Admin\Resources\AccountMappings\Pages\CreateAccountMapping;
use App\Filament\Admin\Resources\ChargeCodes\Pages\CreateChargeCode;
use App\Support\PostingRoles;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * SW-145 — the posting-role hint could not appear while the role was being chosen.
 *
 * Both posting-role pickers carry a helper that reads their OWN state: *"Normally points at a
 * Revenue account."* It is the one sentence that tells an accountant whether the chart account they
 * are about to pick belongs under the role they just selected, and it is worth most at exactly the
 * moment the pair is being decided.
 *
 * Neither field was `->live()`. Measured at HEAD by reflecting on the built schema: `isLive` is null
 * on `AccountMappingForm`'s `key` and on `ChargeCodeForm`'s `posting_role`, and `isLive()` falls
 * through the Section to the root Schema, which answers false — so the binding is deferred, nothing
 * reaches the server when the role changes, and the sentence first appeared after a SAVE, on an edit
 * page, about a decision already taken. `tax_code`, three fields below the second one, sets
 * `->live()` for exactly this reason and its rate hint works.
 *
 * **Which assertion proves what.** The `isLive()` cases are the fix: a Livewire test writes state
 * directly, so a test that only set the field and read the helper back would pass with `->live()`
 * deleted. The helper cases are the control — they prove the sentence `->live()` delivers is a real
 * one and differs from the generic one, without which making the field live would buy nothing.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('sends the posting map role to the server as it is chosen', function () {
    Livewire::test(CreateAccountMapping::class)
        ->assertFormFieldExists('key', fn (Select $field): bool => $field->isLive());
});

it('sends the charge code role to the server as it is chosen', function () {
    // The same field, the same helper key, the same defect one file away. Fixing one and leaving
    // the other is the half-a-screen shape this repo keeps recording.
    Livewire::test(CreateChargeCode::class)
        ->assertFormFieldExists('posting_role', fn (Select $field): bool => $field->isLive());
});

it('names the account group the chosen role expects', function () {
    $generic = __('admin.helpers.posting_role');
    $expected = __('admin.helpers.posting_role_expects', [
        'group' => PostingRoles::groupLabel((string) PostingRoles::group('rent_revenue')),
    ]);

    // If both sentences were the same there would be nothing for a live binding to deliver, and the
    // two cases above would be pinning a round-trip that changes nothing on screen.
    expect($expected)->not->toBe($generic);

    // Asserted on the RENDERED page: Filament v4.11 has no `getHelperText()` reader — `helperText()`
    // composes into `belowContent()` — so the only honest probe is what the operator sees.
    Livewire::test(CreateAccountMapping::class)
        ->assertSee($generic)
        ->set('data.key', 'rent_revenue')
        ->assertSee($expected);
});
