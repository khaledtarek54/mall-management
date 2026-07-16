<?php

namespace App\Support;

use App\Models\ApprovalRule;
use App\Models\User;

/**
 * "Does this need signing off, and by whom?" (FR-CM-11, FR-PROC-02).
 *
 * The one place that turns an amount into an approval requirement. Nothing else should
 * read `approval_rules` — a second interpretation of the ladder is how two parts of the
 * system come to disagree about who may approve a 5,000 EGP draw.
 *
 * **Fail-closed.** If no band covers an amount, the answer is the HIGHEST tier, not "no
 * approval needed". A gap in the ladder (someone edits the bands and leaves a hole, or a
 * value lands above every band because nobody thought that big) must make approval harder,
 * never wave the spend through. The seeded bands tile the whole line, so the gap case is a
 * misconfiguration — and misconfiguration should be safe.
 */
class ApprovalPolicy
{
    /**
     * The permission required to approve $amount in $module — or null when the module has
     * no ladder configured at all (approval is off for it).
     */
    public static function permissionFor(string $module, float $amount): ?string
    {
        $rules = ApprovalRule::query()
            ->active()
            ->forModule($module)
            ->orderBy('min_amount')
            ->get();

        if ($rules->isEmpty()) {
            // No ladder = the module isn't gated. Deliberate: an operator who hasn't set up
            // approval for procurement shouldn't find procurement unusable.
            return null;
        }

        $match = $rules->first(fn (ApprovalRule $rule) => $rule->covers($amount));

        if ($match !== null) {
            return $match->required_permission;
        }

        // Fail closed — see the class docblock. The STRICTEST tier configured for the
        // module, not merely the last band: nothing forces a band's tier to rise with its
        // amount, so "the highest band" is not "the highest authority". Configured out of
        // order (0–100 → tier 3, 5000+ → tier 1), picking the last band would hand a gap
        // the WEAKEST tier — failing open, in the one mechanism that exists to fail closed.
        return $rules
            ->sortByDesc(fn (ApprovalRule $r) => self::strictness($r->required_permission))
            ->first()
            ->required_permission;
    }

    /**
     * How senior a permission is, for ordering. A permission outside the tier ladder is
     * treated as the strictest thing there is — an unrecognised requirement is not a licence
     * to skip it.
     */
    private static function strictness(string $permission): int
    {
        $index = array_search($permission, ApprovalRule::TIERS, true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    /** Is approval required at all for this amount? */
    public static function isRequired(string $module, float $amount): bool
    {
        return self::permissionFor($module, $amount) !== null;
    }

    /**
     * Is this user's approval sufficient for $amount in $module?
     *
     * A user holding a HIGHER tier can approve a lower band: authority is cumulative, so
     * a manager isn't blocked from approving a small draw a supervisor could have handled.
     * That is the difference between a ladder and four disconnected locks.
     *
     * ⚠️ **This answers the approval question ONLY — it is not an action gate.** When a
     * module has no ladder configured nothing needs approving, so this returns true for
     * *any* signed-in user, viewer included. The caller must still check that the user may
     * perform the action at all (`inventory.create`, etc.). Reading this alone as
     * permission to proceed would let a read-only user act the moment someone deletes the
     * bands. Use `isRequired()` when you want "does this need signing off?".
     */
    public static function canApprove(?User $user, string $module, float $amount): bool
    {
        if ($user === null) {
            return false;
        }

        $required = self::permissionFor($module, $amount);

        if ($required === null) {
            return true; // no ladder → nothing to approve; the caller still gates the action
        }

        $tiers = ApprovalRule::TIERS;
        $index = array_search($required, $tiers, true);

        if ($index === false) {
            // A custom permission outside the tier ladder — check it literally.
            return $user->can($required);
        }

        // Any tier at or above the required one.
        foreach (array_slice($tiers, $index) as $tier) {
            if ($user->can($tier)) {
                return true;
            }
        }

        return false;
    }
}
