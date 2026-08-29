<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ConvertLeaseToHoldoverService;
use Carbon\CarbonImmutable;

/**
 * `holdover_rate_pct` IS A PERCENTAGE OF THE LAST RENT, NOT A PREMIUM ON TOP.
 *
 * `ConvertLeaseToHoldoverService` computes `$lastRent * $rate / 100`, the shipped default is 150,
 * and the modal's own hint says "a percentage of the last rent in force. 150% is the standard".
 * But `admin.fields.holdover_rate_pct` called it an "Holdover uplift %" — the label the AUDIT TRAIL
 * prints — and CLAUDE.md described it as "the premium on top".
 *
 * The two readings differ threefold and in the direction of undercharging. An operator told to add
 * half again types 50 under the uplift reading and gets 25,000 on a 50,000 rent — half the rent for
 * a tenant who has overstayed their term, when 75,000 was meant.
 *
 * Two teeth. The arithmetic, so a future change to the service cannot quietly adopt the other
 * reading; and the floor, because holding over is a penalty for staying past the term and a rate
 * below 100 prices overstaying below renewing. A genuinely reduced wind-down rent is a rent change
 * or a relief, not a holdover.
 */
it('prices a holdover at the stated percentage OF the last rent', function (): void {
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->startOfMonth(),
        'expiry_date' => now()->subMonth()->endOfMonth(),
        'base_rent_monthly' => 50_000,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 50_000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'rate_pct' => 150,
        'reason' => 'term ran out',
        'effective_from' => CarbonImmutable::today(),
    ]);

    // 150% OF 50,000 — not 50,000 plus 150%, and not 50,000 plus 50%.
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(75_000.0);
});

it('refuses a holdover rate that would cut the rent', function (): void {
    // Read the bound off the source rather than the mounted modal: a Filament field throws
    // outside a live container, which is how a runtime sweep of field metadata once measured
    // nothing at all while reporting a percentage.
    $source = file_get_contents(app_path('Filament/Admin/Actions/LeaseActions.php'));
    $chunk = substr($source, strpos($source, "TextInput::make('rate_pct')"), 1600);

    expect($chunk)->toContain('->minValue(100)');
    expect($chunk)->not->toContain('->minValue(1)');
});

it('labels the field by its basis in both languages', function (): void {
    foreach (['en', 'ar'] as $locale) {
        $label = trans('admin.fields.holdover_rate_pct', [], $locale);

        expect($label)->not->toBe('admin.fields.holdover_rate_pct');

        // "uplift" is the reading that is wrong by a factor of three. The Arabic never said it;
        // the English did, on the label the activity log renders.
        expect(strtolower($label))->not->toContain('uplift');
    }
});
