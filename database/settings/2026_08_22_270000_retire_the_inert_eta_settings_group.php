<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Drop the `eta.*` settings group with the ETA freeze (module 16).
     *
     * All four were INERT — `SettingsReach::KNOWN_INERT` recorded two of them as written by the
     * settings page and read by nothing, and the other two were no better: the submission pipeline
     * reads `config('eta.enabled')` / `config('eta.mock')` from env, never these rows. So the only
     * thing this group ever did was put an "ETA e-Invoicing" tab on the settings screen with two
     * `->required()` fields an operator had to fill in for a module that has never been certified.
     *
     * The issuer identity is not lost and was never the survivor: `TaxSettings::seller_legal_name`
     * and `TaxSettings::seller_tax_registration_number` are the operator's real registration, and
     * that class's own docblock already named this copy as the one to retire. When ETA resumes,
     * `EtaJsonBuilder` should build its issuer block from TaxSettings rather than a second home for
     * the same number — which is what deleting this makes unavoidable rather than optional.
     *
     * `config/eta.php` keeps every endpoint, credential and EGS code the dormant code needs.
     */
    public function up(): void
    {
        foreach (['enabled', 'mock', 'issuer_name', 'issuer_tax_registration_number'] as $name) {
            $this->migrator->deleteIfExists("eta.{$name}");
        }
    }

    public function down(): void
    {
        // Deliberately not reversible into a settings CLASS that no longer exists: spatie would
        // write four rows nothing can read, which is the state this migration exists to end.
    }
};
