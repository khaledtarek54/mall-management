<?php

use App\Models\TenantRequest;

/**
 * Regression (pass-6 review): the count-based reference helper could hand out a
 * reference that already exists (race / out-of-order creation), tripping the
 * unique index. It now bumps the suffix until free (mirrors the money helpers).
 */
it('bumps the request reference past an existing collision', function () {
    $unit = makeUnit(makeAsset());
    $tenant = makeTenant();
    $year = now()->format('Y');

    // Two requests exist with a GAP (0001, 0003) → the count-based candidate
    // (0003) is already taken, so the helper must skip to 0004.
    foreach (["MR-AW-{$year}-0001", "MR-AW-{$year}-0003"] as $ref) {
        TenantRequest::create([
            'reference' => $ref, 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'request_type' => 'maintenance', 'status' => 'submitted', 'priority' => 'medium',
            'category' => 'electrical', 'title' => 'x', 'description' => 'x', 'submitted_at' => now(),
        ]);
    }

    expect(TenantRequest::generateReference('AW', 'MR'))->toBe("MR-AW-{$year}-0004");
});
