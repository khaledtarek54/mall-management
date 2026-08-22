<?php

namespace App\Support;

/**
 * Whether a setting the screen writes is a setting the code reads (CFG-06).
 *
 * The trap this exists for has now been hit three times, and it is invisible from the screen every
 * time: the operator changes a number, the page says "Saved ✓", the value is genuinely in the
 * database — and nothing reads it. There is no error, no red, nothing to notice. It was found by
 * hand each time.
 *
 *   1. `TaxSettings` was written by the settings page and read as `config()` from env, so the
 *      screen did nothing at all.
 *   2. `levy_rate_percent`, `auto_apply_tenant_credit` and `holdover_default_rate_pct` were live
 *      settings with **no screen at all** — CFG-01's registry found those.
 *   3. `default_payment_terms_days` had a screen AND a reader, and was still inert: the reader sat
 *      on the dead branch of a `??` over a NOT NULL column, so it could never fire (CFG-03).
 *
 * Number 3 is why this class has two halves. A grep for the setting's name would have passed it.
 *
 * ## Half one — something reads it
 *
 * Every public property of every registered settings class must appear somewhere in `app/` or
 * `routes/` outside `app/Settings` and the settings page itself. Crude, and it is the floor rather
 * than the ceiling: it catches the setting nothing consults, which is failure mode 1 and 2.
 *
 * ## Half two — the reader is REACHABLE
 *
 * `$lease->payment_terms_days ?? BillingSettings::defaultPaymentTermsDays()` reads exactly like a
 * working fallback. It is dead code when the column is NOT NULL with a database default, which that
 * one is — so at eight billing call sites the setting could never apply, and an operator could set
 * 30 and watch every lease keep billing at 7.
 *
 * So every `?? <settings read>` over a NOT NULL column has to be **declared** here with a reason.
 * Some are legitimately defensive (an unsaved model has no attribute yet); the point is that the
 * decision is made and written down rather than inherited by accident.
 */
class SettingsReach
{
    /**
     * Settings nothing reads, where that is correct.
     *
     * Empty today, and that is the honest state — every unread setting currently on the books is a
     * defect rather than a design choice, and is listed in {@see KNOWN_INERT} instead. Kept because
     * a legitimate case exists: a setting consumed only by a template, or one a future module will
     * read, is a reasonable thing to declare.
     *
     * @var array<string, string>
     */
    public const EXEMPT_NO_READER = [];

    /**
     * Settings the screen writes that NOTHING reads — a defect, parked with a reason.
     *
     * Deliberately a separate list from {@see EXEMPT_NO_READER}. An exemption saying "this is fine"
     * and one saying "this is broken and here is why we have not fixed it" are different claims,
     * and collapsing them is how a real gap becomes invisible again — which is the exact failure
     * this whole class exists to stop. Empty is the goal, not the norm: an entry here is a debt.
     *
     * @var array<string, string>
     */
    public const KNOWN_INERT = [
        // Empty since 2026-08-22. The only two entries were `eta.issuer_name` and
        // `eta.issuer_tax_registration_number` — required fields on a settings tab that nothing
        // read — and they were not fixed by wiring a reader: the whole `eta.*` group was DELETED
        // with the ETA freeze (App\Support\Modules::FROZEN), because the surviving home for a
        // seller's registration number is `TaxSettings`, not a second copy owned by an integration
        // that may be switched off. Retiring the setting is the fix that a reader would have hidden.
    ];

    /**
     * `?? <settings read>` sites over a NOT NULL column, declared deliberate.
     *
     * Keyed `Relative/Path.php:column`. A site NOT listed here fails the build, because the default
     * assumption has to be that an unreachable fallback is a mistake — that is what
     * `default_payment_terms_days` was.
     *
     * @var array<string, string>
     */
    public const NOT_NULL_FALLBACKS = [
        // `leases.payment_terms_days` is NOT NULL with a database default, so for any PERSISTED
        // lease this fallback cannot fire — and that is the whole point: the property/portfolio
        // default applies at ORIGINATION (the lease form pre-fills it), never at billing time, so
        // changing a property default cannot re-age receivables already raised.
        //
        // It is kept rather than deleted because an UNSAVED model genuinely has no attribute yet,
        // and `(int) null` would silently mean same-day terms.
        // Re-keyed 2026-08-15 when paymentTermsDays() moved into ActsAsBillableAgreement. THIS
        // REGISTRY IS KEYED BY FILE PATH, so relocating a method breaks its entry and nothing in the
        // method or its callers says so — grep here before moving anything out of a model.
        'app/Models/Concerns/Lease/ActsAsBillableAgreement.php:payment_terms_days' => 'NOT NULL with a database default; the fallback is defensive for an unsaved model, and the real default applies at lease origination',
    ];

    /** The settings-reading expressions half two looks for on the right of a `??`. */
    public const SETTINGS_READ_MARKERS = [
        'Settings::class)->',
        'PropertySettings::',
        'AgingBuckets::',
        'Vat::',
    ];

    /** `group.name` for every registered setting. */
    public static function keys(): array
    {
        $keys = [];

        foreach (SettingsRegistry::classes() as $class) {
            foreach (SettingsRegistry::propertiesOf($class) as $property) {
                $name = is_string($property) ? $property : $property->getName();
                $keys[] = $class::group().'.'.$name;
            }
        }

        return $keys;
    }
}
