<?php

use App\Models\TenantSalesDeclaration;
use App\Notifications\SalesDeclarationLockedNotification;

/**
 * The locked-declaration email's billing hint ("...billed on a separate invoice...") only holds when
 * an overage was actually billed. A locked declaration UNDER the breakpoint (owed 0) creates no
 * invoice — so the hint must be omitted, else the tenant is told to look for an invoice that doesn't
 * exist. (lock() deliberately still notifies a zero-owed declaration.)
 */
function lockedDecl(float $owed): TenantSalesDeclaration
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
    ]);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 40000, 'calculated_percentage_rent' => $owed, 'status' => 'locked',
        'declared_at' => now(), 'declared_by_type' => $lease->tenant::class, 'declared_by_id' => $lease->tenant_id,
    ]);
}

it('omits the billing hint on a zero-owed (under-breakpoint) locked declaration', function () {
    $mail = (new SalesDeclarationLockedNotification(lockedDecl(0)))->toMail(makeTenant());
    $hint = __('admin.notifications.sales_locked_billing_hint');

    expect(collect($mail->introLines)->contains($hint))->toBeFalse();
});

it('includes the billing hint when an overage was billed', function () {
    $mail = (new SalesDeclarationLockedNotification(lockedDecl(2500)))->toMail(makeTenant());
    $hint = __('admin.notifications.sales_locked_billing_hint');

    expect(collect($mail->introLines)->contains($hint))->toBeTrue();
});
