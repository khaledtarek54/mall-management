<?php

declare(strict_types=1);

use App\Models\Lease;

/**
 * A NEGATIVE RENT PRODUCES A LEASE THAT BILLS NOTHING, AND LOOKS PRICED.
 *
 * `minValue(0)` on the form and `min:0` on the importer, with nothing behind either — the same
 * situation the negative-deposit guard in this model was written for, and a worse consequence.
 * `LeaseCreationService` writes its schedule rows under `if ($rent > 0)` / `if ($service > 0)`, so
 * a negative figure yields a lease with no base-rent row, no marketing levy and nothing to bill for
 * the whole of its term, while the lease's own screen shows the figure that was typed.
 *
 * ZERO stays legal and the control test says so: a rent-free fit-out period, a kiosk let on
 * percentage rent alone, and a service charge folded into the rent are all real leases.
 */
it('refuses a negative rent or service charge', function (string $column): void {
    $lease = Lease::factory()->make([$column => -5_000]);

    expect(fn () => $lease->save())->toThrow(DomainException::class);
})->with(['base_rent_monthly', 'service_charge_monthly']);

it('still allows zero', function (): void {
    $lease = Lease::factory()->create([
        'base_rent_monthly' => 0,
        'service_charge_monthly' => 0,
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(0.0);
});

it('still allows an ordinary priced lease', function (): void {
    $lease = Lease::factory()->create([
        'base_rent_monthly' => 40_000,
        'service_charge_monthly' => 5_000,
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(40_000.0);
});

it('words the refusal in both languages, naming the field', function (): void {
    foreach (['en', 'ar'] as $locale) {
        $message = trans('admin.errors.negative_lease_amount', ['field' => 'X'], $locale);

        expect($message)->not->toBe('admin.errors.negative_lease_amount')
            ->and($message)->toContain('X');
    }

    // The Arabic must be Arabic — `Lang::has()` falls back to English, so a key present only in
    // the English file reads as translated. Assert on the script instead.
    expect(trans('admin.errors.negative_lease_amount', ['field' => 'X'], 'ar'))
        ->toMatch('/\p{Arabic}/u');
});
