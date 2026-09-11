<?php

namespace App\Support;

use App\Models\Charge;
use App\Models\Lease;

/**
 * HOW a charge row steps on the lease anniversary — the one reading the sweep, the ladder
 * projection, the schedule tab and the lease form all take.
 *
 * Client meeting 2026-09-02, point 24 — *"the annual increase should be on all expenses not only
 * on rent — better to be an option to be a percentage or a fixed number"*. Yardi holds the
 * escalation schedule PER CHARGE CODE (method, floor/ceiling, frequency — benchmark 01 §4); MRI's
 * recurring charge carries its own step; and Egyptian mall contracts state one increase for the
 * rent and the service charge and routinely a fixed step for a bay. Atriom held ONE lease-level
 * clause for the rent, a toggle that let the service charge follow it, and nothing at all for any
 * other row. This is Yardi's grain: the rule is a TERM OF THE CHARGE ROW, carried onto every
 * successor rung exactly as `billing_timing` and `prorate` are.
 *
 * Four modes. `follows_lease` — the same collared percentage as the rent, on the same anniversary
 * (what the 2026-09-05 toggle meant, and what "the rent and service charge shall increase by 7%"
 * says); `percent` and `fixed_amount` — the row's own figure; `none` — nothing, which is what a
 * null column reads as, so a row nobody ruled on steps nothing. The rent's own rows carry no mode:
 * the lease's clause IS the rent's rule, and the marketing levy follows the rent by derivation.
 *
 * **One anniversary, one interval — the lease's.** Voyager lets each charge carry its own
 * frequency and effective date; that is deliberately not copied. Every Egyptian clause these malls
 * sign steps on the contract's anniversary, and a per-row calendar would be a decision surface
 * nobody reads (skill §3b). Stated as the deviation it is.
 *
 * **A follows-lease row under an AMOUNT clause steps nothing.** A step stated in pounds is a
 * statement about the rent — adding the same EGP 5,000 to a service charge a fraction of its size
 * charges nobody what they agreed — which is the 2026-09-05 rule and the reasoning that keeps the
 * collar off `fixed_amount`. A row that should step by its own amount says so in its own mode.
 */
final class ChargeEscalation
{
    public const FOLLOWS_LEASE = 'follows_lease';

    public const PERCENT = 'percent';

    public const FIXED_AMOUNT = 'fixed_amount';

    public const NONE = 'none';

    public const MODES = [self::FOLLOWS_LEASE, self::PERCENT, self::FIXED_AMOUNT, self::NONE];

    /**
     * Charge types whose step is decided elsewhere and never asked here — the rent by the lease's
     * own clause, the levy by the rent it is a percentage of, and PARKING by the rentable-items
     * register: a bay carries its own `monthly_rate` there and `AssignRentableItemService`
     * re-derives the one parking row from the sum on every assignment and release, so a rule on
     * the row would be undone by the next bay (found by review — the first cut let the row carry
     * one and the flagship test case was a bay). Stepping a bay belongs to that register, per
     * item, and is deliberately not in this change. Everything else on a schedule may carry a
     * rule. The schedule tab's own `DERIVED_TYPES` names the same three for the same reason.
     */
    public const DERIVED_TYPES = ['base_rent', 'marketing', 'parking'];

    /** The mode a row carries — a null column is a row nobody ruled on, and it steps nothing. */
    public static function modeOf(Charge $row): string
    {
        $mode = (string) $row->escalation_mode;

        return in_array($mode, self::MODES, true) ? $mode : self::NONE;
    }

    /**
     * The mode a NEW charge is proposed as, per property — the house policy, not a literal.
     * Yardi's answer is `none` (a charge carries no escalation until one is stated) and that is
     * the shipped default; a portfolio whose leases raise every charge together sets
     * `billing.new_charges_follow_escalation` and gets `follows_lease` proposed instead.
     */
    public static function defaultModeFor(?int $assetId): string
    {
        return filter_var(PropertySettings::get('billing.new_charges_follow_escalation', $assetId), FILTER_VALIDATE_BOOL)
            ? self::FOLLOWS_LEASE
            : self::NONE;
    }

    /**
     * Whether the lease's own clause is one a `follows_lease` row can follow — the percent-derived
     * types. Under an amount clause (or none) such a row steps nothing, by the rule in the class
     * docblock.
     */
    public static function clauseIsFollowable(Lease $lease): bool
    {
        return in_array((string) $lease->escalation_type, ['fixed_percent', 'cpi'], true);
    }

    /**
     * The step this row takes on an anniversary, resolved against the lease's clause — or null
     * when it takes none.
     *
     * `$leasePercent` is what a `follows_lease` row inherits: the sweep passes the COLLARED rate
     * it just resolved (from a stated percentage or the index register), the projection passes
     * the raw stated rate for `fixed_percent` and null for `cpi` — an index figure that has not
     * been published cannot be projected, which is the sweep's own refusal to invent. So a
     * follows-lease row projects exactly where the rent does and waits exactly where it waits.
     *
     * @return array{percent: float}|array{amount: float}|null
     */
    public static function stepFor(Charge $row, Lease $lease, ?float $leasePercent): ?array
    {
        return match (self::modeOf($row)) {
            self::FOLLOWS_LEASE => self::clauseIsFollowable($lease) && $leasePercent !== null && $leasePercent > 0
                ? ['percent' => round($leasePercent, 2)]
                : null,
            self::PERCENT => (float) $row->escalation_rate > 0
                ? ['percent' => round((float) $row->escalation_rate, 2)]
                : null,
            self::FIXED_AMOUNT => (float) $row->escalation_amount > 0
                ? ['amount' => round((float) $row->escalation_amount, 2)]
                : null,
            default => null,
        };
    }

    /** The figure a step produces from a base — one arithmetic, so the sweep and the ladder agree. */
    public static function apply(float $base, array $step): float
    {
        return isset($step['amount'])
            ? round($base + $step['amount'], 2)
            : round($base * (1 + $step['percent'] / 100), 2);
    }

    /**
     * Whether this row carries a rule at all — the query-free half of "does this lease escalate
     * anything beyond its rent", asked of rows already loaded.
     */
    public static function isStated(Charge $row): bool
    {
        return self::modeOf($row) !== self::NONE;
    }

    /**
     * The rule in words, for the schedule tab and the form's summary: what it follows and by how
     * much, or that it stands still. The lease is read for what a follows-lease row inherits, so
     * the sentence names the figure rather than the word "clause".
     */
    public static function describe(Charge $row, Lease $lease): string
    {
        $mode = self::modeOf($row);

        if ($mode === self::FOLLOWS_LEASE) {
            if (! self::clauseIsFollowable($lease)) {
                return __('admin.charge_escalation.follows_nothing');
            }

            return (string) $lease->escalation_type === 'cpi'
                ? __('admin.charge_escalation.follows_index')
                : __('admin.charge_escalation.follows_rate', ['rate' => self::trimmed((float) $lease->escalation_rate)]);
        }

        return match ($mode) {
            self::PERCENT => __('admin.charge_escalation.own_percent', ['rate' => self::trimmed((float) $row->escalation_rate)]),
            self::FIXED_AMOUNT => __('admin.charge_escalation.own_amount', ['amount' => number_format((float) $row->escalation_amount, 2)]),
            default => __('admin.charge_escalation.none'),
        };
    }

    /**
     * The picker's options. `follows_lease` names what it would inherit — a bare "Follows the
     * clause" over a lease whose clause is a fixed amount offers a choice that does nothing, and
     * saying so in the option is cheaper than a helper nobody reads.
     *
     * @return array<string, string>
     */
    public static function options(?Lease $lease = null): array
    {
        $follows = match (true) {
            $lease === null => __('admin.charge_escalation.modes.follows_lease'),
            ! self::clauseIsFollowable($lease) => __('admin.charge_escalation.modes.follows_lease_inert'),
            (string) $lease->escalation_type === 'cpi' => __('admin.charge_escalation.modes.follows_lease_index'),
            default => __('admin.charge_escalation.modes.follows_lease_rate', ['rate' => self::trimmed((float) $lease->escalation_rate)]),
        };

        return [
            self::FOLLOWS_LEASE => $follows,
            self::PERCENT => __('admin.charge_escalation.modes.percent'),
            self::FIXED_AMOUNT => __('admin.charge_escalation.modes.fixed_amount'),
            self::NONE => __('admin.charge_escalation.modes.none'),
        ];
    }

    /** `7.50` → `7.5`, `7.00` → `7` — a rate reads as the contract wrote it. */
    private static function trimmed(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.');
    }
}
