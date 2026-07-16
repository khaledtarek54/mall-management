<?php

namespace Database\Seeders;

use App\Models\ApprovalRule;
use Illuminate\Database\Seeder;

/**
 * The default approval ladder (FR-CM-11: "higher-value parts require higher-level approval").
 *
 * These amounts are a **business-policy default, not a documented figure** — the FRD gives
 * no numbers at all. They are recorded in docs/BUSINESS-RULES.md for the operator to
 * confirm, and they are data: changing a band is configuration, not a deploy.
 *
 * The bands **tile the whole line** (0→1k→10k→∞) with no gap and no overlap: min inclusive,
 * max exclusive. ApprovalPolicy fails closed if a gap is ever introduced, but a ladder that
 * needs its own safety net to be correct is one bad edit from surprising someone.
 */
class ApprovalRulesSeeder extends Seeder
{
    public function run(): void
    {
        $bands = [
            // [module, min, max (null = unbounded), required permission]
            [ApprovalRule::MODULE_INVENTORY_DRAW, 0, 1000, ApprovalRule::TIER_1],
            [ApprovalRule::MODULE_INVENTORY_DRAW, 1000, 10000, ApprovalRule::TIER_2],
            [ApprovalRule::MODULE_INVENTORY_DRAW, 10000, null, ApprovalRule::TIER_3],

            // FR-PROC-02. Same bands as a stock draw: the client described exactly one hierarchy
            // (FR-CM-11's price-based one) and never said procurement differs, so inventing a
            // second ladder would be inventing policy. Their answer is a row change.
            [ApprovalRule::MODULE_PURCHASE_REQUEST, 0, 1000, ApprovalRule::TIER_1],
            [ApprovalRule::MODULE_PURCHASE_REQUEST, 1000, 10000, ApprovalRule::TIER_2],
            [ApprovalRule::MODULE_PURCHASE_REQUEST, 10000, null, ApprovalRule::TIER_3],
        ];

        foreach ($bands as [$module, $min, $max, $permission]) {
            ApprovalRule::updateOrCreate(
                ['module' => $module, 'min_amount' => $min],
                ['max_amount' => $max, 'required_permission' => $permission, 'is_active' => true],
            );
        }
    }
}
