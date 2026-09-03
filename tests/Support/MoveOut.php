<?php

namespace Tests\Support;

use App\Models\Charge;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Support\Vat;

/**
 * The fixture two move-out files share: a tenancy about to end, with a deposit actually held.
 *
 * **A class, not a file-scope function.** A Pest worker only loads the test files it owns, so a
 * helper declared at file scope cannot be reached from a second file — and declaring it in both is
 * a fatal redeclaration during collection that exits the whole suite 255 with no output on either
 * stream. `TestHelperUniquenessConformanceTest` is the gate; this is the shape it points at.
 */
class MoveOut
{
    /**
     * A lease running to the end of 2030, at 180,000 a month, with `$deposit` receipted and held.
     *
     * The deposit is a RECORDED transaction because that is the only kind the statement counts — a
     * draft is an intention, and settling against intentions is how a landlord refunds money it
     * never received.
     */
    public static function lease(float $deposit = 540000): Lease
    {
        $lease = makeLease(makeUnit(makeAsset()), null, [
            'status' => 'active',
            'commencement_date' => '2028-01-01',
            'expiry_date' => '2030-12-31',
            'base_rent_monthly' => 180000,
            'security_deposit' => $deposit,
            'has_marketing_levy' => false,
        ]);

        Charge::create([
            'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
            'origin' => Charge::ORIGIN_SEED, 'amount' => 180000, 'currency' => 'EGP',
            'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
            'start_date' => '2028-01-01', 'is_active' => true,
        ]);

        DepositTransaction::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'asset_id' => $lease->unit->asset_id,
            'type' => 'receipt',
            'amount' => $deposit,
            'transaction_date' => '2028-01-01',
            'status' => 'recorded',
        ]);

        return $lease->fresh();
    }
}
