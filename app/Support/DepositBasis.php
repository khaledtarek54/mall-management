<?php

namespace App\Support;

use App\Models\Lease;

/**
 * HOW a security deposit was agreed, and the one place the figure is derived from it.
 *
 * Client meeting 2026-09-02, point 3 — *"the security deposit as a % or a fixed amount"*. The market
 * standard is a FIXED sum or a MULTIPLE of the monthly rent: Yardi's deposit is an amount on a
 * deposit charge with the requirement tracked against rent, MRI and Entrata offer "N months"; the
 * lease has expressed both since EG-35 (`security_deposit_months` set = a multiple, null = flat).
 * "A percentage of the ANNUAL rent" is not a Voyager concept — it is the Egyptian / GCC clause
 * convention, and arithmetically a multiple by another name (10% of annual = 1.2 months). So it is a
 * third BASIS on the same field, not a second deposit: the lease records how the figure was agreed,
 * and {@see derive()} is the single arithmetic every writer reads — the model's `saving` hook, the
 * wizard, the form's live preview — so a renewal, an escalation or an import cannot price the same
 * clause three ways.
 *
 * **A rent-linked basis re-derives when rent moves; a fixed one never does.** That is EG-35's rule
 * ("a deposit agreed as three months' rent stays three months' rent"), now stated for both linked
 * bases. `fixed` is the operator's own figure and nothing touches it.
 */
final class DepositBasis
{
    /** A multiple of the MONTHLY rent — `security_deposit_months`. Yardi/MRI's shape; the default. */
    public const MONTHS = 'months';

    /** A percentage of the ANNUAL rent — `security_deposit_percent`. The Egyptian / GCC clause. */
    public const PERCENT_OF_ANNUAL_RENT = 'percent_of_annual_rent';

    /** A sum the operator typed, unrelated to rent. */
    public const FIXED = 'fixed';

    public const ALL = [self::MONTHS, self::PERCENT_OF_ANNUAL_RENT, self::FIXED];

    /** Whether the basis derives the figure from the rent — the two that re-derive when rent moves. */
    public static function isRentLinked(?string $basis): bool
    {
        return in_array($basis, [self::MONTHS, self::PERCENT_OF_ANNUAL_RENT], true);
    }

    /**
     * The deposit a basis implies for a monthly rent, or null when the basis is fixed (or the
     * figure it needs is not stated — a percent basis with no percent proposes nothing rather than
     * zero, so a half-filled form does not silently agree a deposit of 0.00).
     */
    public static function derive(?string $basis, float $monthlyRent, ?float $months, ?float $percent): ?float
    {
        return match ($basis) {
            self::MONTHS => $months === null ? null : round($monthlyRent * $months, 2),
            self::PERCENT_OF_ANNUAL_RENT => $percent === null ? null : round($monthlyRent * 12 * $percent / 100, 2),
            default => null,
        };
    }

    /** The figure this lease's own basis implies, from its own columns. */
    public static function for(Lease $lease): ?float
    {
        return self::derive(
            $lease->security_deposit_basis,
            (float) $lease->base_rent_monthly,
            $lease->security_deposit_months === null ? null : (float) $lease->security_deposit_months,
            $lease->security_deposit_percent === null ? null : (float) $lease->security_deposit_percent,
        );
    }

    /**
     * The property's proposed basis and figure for a NEW lease — the house policy, per building.
     *
     * @return array{basis: string, months: ?float, percent: ?float}
     */
    public static function defaultsFor(?int $assetId): array
    {
        $basis = (string) PropertySettings::get('billing.default_security_deposit_basis', $assetId);

        if (! in_array($basis, self::ALL, true)) {
            $basis = self::MONTHS;
        }

        // A percent basis whose percent is 0 proposes NOTHING — so it falls back to months rather
        // than proposing a 0.00 deposit that then walks through the money gate (`held 0 >= required
        // 0`). The same rule `derive()` states for a half-filled form, applied to a half-set
        // policy; the setting ships at 0 because a guessed percentage is a wrong one.
        if ($basis === self::PERCENT_OF_ANNUAL_RENT
            && (float) PropertySettings::get('billing.default_security_deposit_percent', $assetId) <= 0) {
            $basis = self::MONTHS;
        }

        return [
            'basis' => $basis,
            'months' => $basis === self::MONTHS ? (float) PropertySettings::get('billing.default_security_deposit_months', $assetId) : null,
            'percent' => $basis === self::PERCENT_OF_ANNUAL_RENT ? (float) PropertySettings::get('billing.default_security_deposit_percent', $assetId) : null,
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::ALL)->mapWithKeys(fn (string $b) => [$b => __('admin.deposit_basis.'.$b)])->all();
    }
}
