<?php

use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Support\Vat;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;

/**
 * **DELETING THE LAST RATE OF A LIVE TAX CODE SILENTLY RE-RATED BILLING TO THE VAT FLOOR** (SW-201).
 *
 * `TaxCode::assertCanBeActivated()` refuses to switch a rate-less standard code ON — *"otherwise it
 * would appear in the pickers and bill nothing"*. Nothing had ever asked the same question in the
 * other direction, and the rate ladder is an ordinary relation manager with an ordinary Delete on
 * every row.
 *
 * Measured against HEAD on the seeded catalogue: remove the only rung of `STAMP_20` (active,
 * standard, 20% since 2017-07-01) and `TaxCode::rateOn()` answers null, so `Vat::rateForType()`
 * falls past its own floor into `standardRate()` — every supply the accountant had ruled carries
 * 20% stamp duty originates at **14% VAT** from the next document on. No error, no toast, and the
 * code still reads *active* on the screen it was just emptied from.
 *
 * The guard is on the MODEL rather than on the button: it is the same rule as
 * `assertCanBeActivated()`, both now ask `TaxCode::needsARateLadder()` so the pair cannot drift, and
 * a second door onto the ladder is covered by existing rather than by being remembered. (A mass
 * `$code->rates()->delete()` fires no model event and is not covered — that is the same known limit
 * as the memo flush, and no such call exists in `app/`.)
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    // The ordinary shipped arrangement, not a fixture invented for this: STAMP_20 is active,
    // standard-rated and carries exactly one rung.
    $this->stamp = TaxCode::query()->where('code', 'STAMP_20')->sole();

    expect($this->stamp->is_active)->toBeTrue()
        ->and($this->stamp->treatment)->toBe(TaxCode::STANDARD)
        ->and($this->stamp->rates()->count())->toBe(1);

    // The accountant's ruling: this supply carries stamp duty rather than VAT. One row, no deploy —
    // which is exactly why the code can be pointed somewhere and then emptied by somebody else.
    ChargeCode::query()->where('code', 'service_charge')->sole()->update(['tax_code' => 'STAMP_20']);

    expect(Vat::rateForType('service_charge', '2026-06-01'))->toBe(20.0);
});

it('refuses to leave a live tax code with no rate at all', function () {
    $rung = $this->stamp->rates()->sole();

    expect(fn () => $rung->delete())->toThrow(DomainException::class);

    TaxCode::flushLookupCaches();

    // The rung is still there — and, the point of it, the rate has not moved. Without the refusal
    // this reads 14.0: the VAT floor, standing in for a stamp duty nobody withdrew.
    expect(TaxRate::query()->whereKey($rung->getKey())->exists())->toBeTrue()
        ->and(Vat::rateForType('service_charge', '2026-06-01'))->toBe(20.0);
});

it('lets go of a rung the ladder can spare', function () {
    // The control that must succeed. A rate change is a NEW rung, and correcting a mis-keyed one is
    // ordinary work — a blanket refusal would take that away and is the trap this codebase records
    // for #[NeverDeletable].
    // Taken BEFORE the second rung exists, rather than looked up by date: a `date` cast is written
    // through the connection's datetime format, so MySQL truncates it to `2017-07-01` and SQLite
    // keeps `2017-07-01 00:00:00` — an equality lookup here would be green on one driver only.
    $original = $this->stamp->rates()->sole();

    $this->stamp->rates()->create(['rate' => 25, 'effective_from' => '2026-01-01']);

    $original->delete();

    TaxCode::flushLookupCaches();

    expect(TaxRate::query()->whereKey($original->getKey())->exists())->toBeFalse()
        // …and the code still answers, which is the property actually being protected.
        ->and(Vat::rateForType('service_charge', '2026-06-01'))->toBe(25.0);
});

it('lets a switched-off code be emptied, which is the escape the refusal names', function () {
    $this->stamp->update(['is_active' => false]);

    $rung = $this->stamp->rates()->sole();

    $rung->delete();

    expect(TaxRate::query()->whereKey($rung->getKey())->exists())->toBeFalse();
});

it('lets an exempt code be emptied, because its ladder decides nothing', function () {
    // `rateOn()` answers 0 for any non-standard treatment whatever the ladder holds, so a rung here
    // is policy the resolver never reads and a guard that fired would refuse a deletion that moves
    // no figure. VAT_EXEMPT ships active with one 0.000 rung.
    $exempt = TaxCode::query()->where('code', 'VAT_EXEMPT')->sole();

    expect($exempt->is_active)->toBeTrue()
        ->and($exempt->treatment)->toBe(TaxCode::EXEMPT);

    $rung = $exempt->rates()->sole();

    $rung->delete();

    expect(TaxRate::query()->whereKey($rung->getKey())->exists())->toBeFalse();
});
