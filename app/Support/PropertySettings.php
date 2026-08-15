<?php

namespace App\Support;

use App\Models\PropertySetting;
use App\Settings\BillingSettings;


/**
 * What a property answers differently from the portfolio.
 *
 * Eltizam runs several malls and every configured number was portfolio-wide — one late-fee rate,
 * one grace period, one set of payment terms across every building. That is neither how the leases
 * read nor how the benchmark systems work: Yardi configures late fees, billing day and SLA hours
 * per property.
 *
 * ## Resolution, and why the middle tier is the only new one
 *
 *   1. The **LEASE**'s own term, where it has one. A negotiated figure always wins.
 *   2. The **PROPERTY** — this class.
 *   3. The **PORTFOLIO** — the settings screen. Always answerable, which is what makes the whole
 *      chain safe: an unconfigured property is not an unconfigured system.
 *
 * ## Ask for the property explicitly
 *
 * {@see get()} takes an asset id. It does NOT read the currently-selected property from the panel,
 * and that is deliberate: the callers are billing services that also run from the scheduler and the
 * queue, where there is no selected property. A resolver that silently fell back to "whatever is on
 * screen" would give one answer in a request and another in the nightly run, on money.
 *
 * ## Overridable is an allow-list, not a default
 *
 * Most settings must NOT vary by property, and saying so is the point of {@see OVERRIDABLE}: the
 * seller's tax registration number is company identity, VAT rates are national, payroll rates are
 * statutory, and a module is switched on for the system or not at all. An override on any of those
 * would be a way to make one mall file a different return.
 *
 * Every entry here is also WIRED — something reads it. An override that sets a value nothing
 * consults is worse than no override: the operator changes it, sees it saved, and nothing happens.
 * `PropertySettingsConformanceTest` holds both halves.
 */
class PropertySettings
{
    /**
     * The settings a property may answer for itself, and why each one legitimately differs.
     *
     * @var array<string, array{class: class-string, reason: string}>
     */
    public const OVERRIDABLE = [
        'billing.late_fee_percent' => [
            'class' => BillingSettings::class,
            'reason' => 'Late-fee terms are negotiated per building — a prime mall and a secondary one do not charge the same penalty, and the lease tier above this already assumes the number varies.',
        ],
        'billing.late_fee_grace_days' => [
            'class' => BillingSettings::class,
            'reason' => 'The grace period travels with the late-fee rate; splitting them across tiers would let a property set a rate it never applies.',
        ],
        'billing.late_fee_minimum' => [
            'class' => BillingSettings::class,
            'reason' => 'A floor that is meaningful in a prime mall is punitive in a smaller one, on the same percentage.',
        ],
        'billing.default_payment_terms_days' => [
            'class' => BillingSettings::class,
            'reason' => 'How long a tenant has to pay is a leasing convention that differs by building, and it decides when a receivable ages.',
        ],
        'billing.nsf_fee_amount' => [
            'class' => BillingSettings::class,
            'reason' => 'The bounced-cheque fee is a lease term like the late fee, and is charged under the same clause.',
        ],
    ];

    /**
     * Deliberately NOT here, where the omission is the interesting part.
     *
     * **SLA hours** are already overridable per property, by `sla_policies` — an active row for a
     * property + priority, resolved as tier 1 of `SlaResolver`'s chain, with its own resource and
     * its own separate response-vs-resolution split. Listing them here would be a SECOND way to say
     * the same thing, and the two would answer differently the first time somebody used the newer
     * one. Per-property SLA is a solved problem; this is not where it lives.
     *
     * **Tax rates, the seller's registration number, ETA credentials, payroll rates and module
     * switches** are not property questions at all. Tax is national, the registration is company
     * identity, payroll is statutory, and a module is on for the system or not. An override on any
     * of them would be a way to make one mall file a different return.
     *
     * @var array<int, string>
     */
    public const NOT_OVERRIDABLE_BY_DESIGN = [
        'sla.sla_urgent_hours',
        'sla.sla_high_hours',
        'sla.sla_medium_hours',
        'sla.sla_low_hours',
    ];

    /**
     * The value for a property, or the portfolio's when it has not overridden it.
     *
     * `$key` is `group.name` — the same shape the settings table uses.
     */
    public static function get(string $key, ?int $assetId): mixed
    {
        $portfolio = self::portfolio($key);

        if ($assetId === null || ! array_key_exists($key, self::OVERRIDABLE)) {
            return $portfolio;
        }

        $override = self::overridesFor($assetId)[$key] ?? null;

        // Absence means "whatever the portfolio says", never zero — which is why this checks for
        // the KEY rather than for a falsy value. A property that overrode the late fee to 0 is
        // making a statement, and it must survive a later change to the portfolio default.
        return $override ?? $portfolio;
    }

    /** Every override a property carries, keyed `group.name`. Memoized per request. */
    public static function overridesFor(int $assetId): array
    {
        $memo = "atriom.property_settings.{$assetId}";

        if (app()->has($memo)) {
            return app($memo);
        }

        $values = PropertySetting::query()
            ->where('asset_id', $assetId)
            ->get(['group', 'name', 'payload'])
            ->mapWithKeys(fn (PropertySetting $row) => ["{$row->group}.{$row->name}" => $row->payload])
            ->all();

        app()->instance($memo, $values);

        return $values;
    }

    /** Write or clear one override. A null value removes it, restoring the portfolio answer. */
    public static function set(string $key, int $assetId, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::OVERRIDABLE), 422, "{$key} may not be overridden per property");

        [$group, $name] = explode('.', $key, 2);

        if ($value === null || $value === '') {
            PropertySetting::query()
                ->where('asset_id', $assetId)
                ->where('group', $group)
                ->where('name', $name)
                ->delete();
        } else {
            PropertySetting::updateOrCreate(
                ['asset_id' => $assetId, 'group' => $group, 'name' => $name],
                ['payload' => $value],
            );
        }

        app()->forgetInstance("atriom.property_settings.{$assetId}");
    }

    /**
     * The payment terms a NEW lease at this property should start from, clamped.
     *
     * One place, because two consumers must agree: `LeaseForm` pre-fills the field with it, and
     * `Lease::paymentTermsDays()` falls back to it. A negative setting is clamped to same-day rather
     * than refused — an operator typo must not produce a due date BEFORE the issue date, and it must
     * not stop the lease form rendering either.
     */
    public static function paymentTermsDays(?int $assetId): int
    {
        return max(0, (int) self::get('billing.default_payment_terms_days', $assetId));
    }

    /** The portfolio-wide answer — the tier that is always available. */
    public static function portfolio(string $key): mixed
    {
        [, $name] = explode('.', $key, 2);
        $class = self::OVERRIDABLE[$key]['class'] ?? null;

        if ($class === null) {
            return null;
        }

        return app($class)->{$name};
    }

    public static function forgetCache(?int $assetId = null): void
    {
        if ($assetId !== null) {
            app()->forgetInstance("atriom.property_settings.{$assetId}");
        }
    }
}
