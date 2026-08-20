<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The address a tenant writes to about a bill.
 *
 * Every issued invoice, tenant statement and asset statement printed a contact address that did not
 * exist: `billing@{property-slug}.test`, built in the Blade out of the mall's own name against the
 * reserved `.test` TLD. A tenant querying an invoice mailed nobody, and the operator never learned
 * they had asked. It has been on documents in tenants' hands.
 *
 * Empty by default and the line is OMITTED when empty, exactly as `seller_tax_registration_number`
 * behaves and for the same reason: a plausible-looking contact on a document is worse than none,
 * because it is trusted, used, and silently fails.
 *
 * Operator-level rather than per-property, which is `App\Support\IssuingEntity`'s standing position:
 * Eltizam is ONE registered entity trading in several malls, so the seller's particulars are the
 * company's. If a mall ever needs its own, that becomes an override on the resolver — never a second
 * copy of the field.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('tax.seller_billing_email', '');
    }

    public function down(): void
    {
        $this->migrator->delete('tax.seller_billing_email');
    }
};
