<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\Asset;
use App\Support\AssignedAssets;
use App\Support\OpsLog;
use DomainException;

/**
 * The write guard for a register whose rows may be PORTFOLIO-WIDE — `asset_id` null meaning
 * "every mall" rather than "no mall".
 *
 * {@see GuardsAssetInScope} answers "may this operator write to the property they named". That is
 * the whole question on an ordinary property-owned resource, where null is refused. It is only half
 * of it here, for two reasons that both produced live holes:
 *
 * **A null row binds every mall, so writing one needs every mall.** Not merely "not refused" —
 * `assertAssetInScope(null)` casts null to 0 and refuses a restricted user, but a screen that
 * legitimately allows null skips the guard for that case and then checks nothing at all.
 *
 * **Taking a date away from a mall is a write to that mall.** A guard that reads only the SUBMITTED
 * value cannot see a row being re-homed. A `mall_admin` pinned to Mall A, looking at the national
 * Eid row the list deliberately shows them, sets Property = Mall A and saves: the submitted value
 * is in scope, the guard passes, and Malls B, C and D silently lose the date — and with it their
 * SLA deadlines and, once the working clock is on, their penalty amounts. This is
 * `UserResource::enforceGrantableAssetsRule()`'s problem exactly, and its answer: revert BOTH
 * directions, and log the attempt.
 */
trait GuardsPortfolioWideRows
{
    /**
     * Guard both ends of a write to a register that allows portfolio-wide rows.
     *
     * @param  ?int  $submittedAssetId  the property the row is being written TO (null = every mall)
     * @param  ?int  $originalAssetId  the property it is being written AWAY from, on an edit
     * @param  string  $refusalKey  translation key for the portfolio-wide refusal
     */
    public static function assertMayWriteAcrossPortfolio(
        ?int $submittedAssetId,
        ?int $originalAssetId,
        string $refusalKey,
    ): void {
        // On a create the two are equal and the second pass is a no-op; on an edit they differ
        // exactly when the row is being re-homed, which is the case a one-directional guard misses.
        $ends = $submittedAssetId === $originalAssetId
            ? [$submittedAssetId]
            : [$submittedAssetId, $originalAssetId];

        foreach ($ends as $assetId) {
            if ($assetId === null) {
                self::assertHoldsEveryProperty($refusalKey);

                continue;
            }

            // A named property the operator cannot see is a 403, as it is on every other screen in
            // the panel. Only the portfolio-wide rule earns its own sentence.
            self::assertAssetInScope($assetId);
        }
    }

    /**
     * Only somebody who can see EVERY mall may write, move or retire a row that binds them all.
     *
     * Measured by COMPARING THE SETS, not by testing `idsForCurrentUser()` for null. Null means
     * super_admin or a never-scoped user — so a null test refuses anyone with an assignment at all,
     * including a user assigned to every property, which is the exact condition this is meant to
     * grant. The panel produces that state by default (`UserForm` pre-selects every property the
     * grantor holds), so assigning staff to the malls they run would silently remove a right they
     * had, and a single-mall deployment would refuse everyone but super_admin.
     *
     * Measured against `AssignedAssets`, NOT `TenantScope::visibleAssetIds()`, for the reason
     * `enforceGrantableAssetsRule()` gives: the latter collapses to the SELECTED property, which
     * would refuse a super_admin who happens to be working inside one mall.
     *
     * A `DomainException`, not `abort(403)`: this is a refusal, and `bootstrap/app.php` renders one
     * as a message. A 403 from a Livewire save is an unexplained wall — and these screens actively
     * invite the operator into it, because the field's helper text and the screen guide both say to
     * leave the property blank.
     */
    private static function assertHoldsEveryProperty(string $refusalKey): void
    {
        $assigned = AssignedAssets::idsForCurrentUser();

        if ($assigned === null) {
            return;
        }

        $everyProperty = Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->pluck('id')
            ->all();

        if (array_diff($everyProperty, $assigned) === []) {
            return;
        }

        OpsLog::warning('A property-restricted user tried to write a portfolio-wide row', [
            'user_id' => auth()->id(),
            'assigned_assets' => $assigned,
            'refusal' => $refusalKey,
        ]);

        throw new DomainException(__($refusalKey));
    }
}
