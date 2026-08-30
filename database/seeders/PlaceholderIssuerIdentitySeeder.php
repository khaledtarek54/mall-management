<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Settings\TaxSettings;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A PLACEHOLDER tax registration, so a test box can exercise the tax-invoice path.
 *
 * ## Why this is its own seeder and not part of LearningSeeder
 *
 * `ConfigurationHealth`'s `seller_tax_identity` row is **blocking**, and it exists to stop exactly
 * one thing: an invoice that titles itself *Tax Invoice* while carrying no registration number.
 * That document is not merely incomplete — it is confidently wrong on the page every tenant files
 * with their own accountant, and the tenant cannot use it to reclaim the VAT they were charged.
 *
 * Seeding a registration therefore turns a blocking safety check GREEN. Folding that into
 * `LearningSeeder` or `DemoSeeder` would mean every learning install reports "Configured" when the
 * operator has configured nothing — the check would be answering on its own behalf. Neither of
 * those seeders sets it, deliberately, and this one is separate so that using it is a decision
 * somebody took rather than a side effect of asking for demo data.
 *
 * ## Why the values look the way they do
 *
 * Real in SHAPE so a tester can judge the document layout — Egyptian registrations are nine digits
 * shown as `xxx-xxx-xxx`. Unmistakable in CONTENT so nothing produced can be taken for a real tax
 * document: nine zeros cannot collide with a live registration, the legal name says STAGING on its
 * face, and the billing address is on `.invalid`, the reserved TLD that can never resolve, so the
 * contact printed on a test document can never reach a real inbox.
 *
 * ## It refuses on production, loudly
 *
 * A throw rather than a silent skip. If someone reaches for this on a production box they have
 * misunderstood something, and the last thing that should happen is a fake registration quietly
 * landing on real invoices while the health check reports everything is fine.
 */
class PlaceholderIssuerIdentitySeeder extends Seeder
{
    public const TRN = '000-000-000';

    public const LEGAL_NAME = 'Eltizam Mall Management (STAGING — NOT A REAL REGISTRATION)';

    public const BILLING_EMAIL = 'billing@staging.invalid';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'PlaceholderIssuerIdentitySeeder refuses to run on production. It sets a FAKE tax '
                .'registration, which would turn the blocking seller_tax_identity health check green '
                .'and put a fabricated number on documents tenants file with their own accountant. '
                .'Set the real registration under Settings → Tax.'
            );
        }

        $settings = app(TaxSettings::class);
        $settings->seller_tax_registration_number = self::TRN;
        $settings->seller_legal_name = self::LEGAL_NAME;
        $settings->seller_billing_email = self::BILLING_EMAIL;
        $settings->save();

        $this->command?->line('   Placeholder issuer identity set — TRN <fg=cyan>'.self::TRN.'</> (NOT REAL).');
    }
}
