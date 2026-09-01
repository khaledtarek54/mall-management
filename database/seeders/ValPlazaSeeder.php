<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Support\Facades\Artisan;

/**
 * The demo estate, branded as VAL PLAZA — the mall this system is being shown for.
 *
 * ## Why a subclass and not a copy
 *
 * `DemoSeeder` is two thousand lines of a mall mid-life: leases part-way through their term, an
 * ageing profile with real arrears, a CAM pool with caps that bite, work orders that ran over.
 * Forking it to change a name would create a second dataset to keep in step, and the one that was
 * not being reseeded daily would rot — the failure this repo already records for parallel doc sets.
 *
 * So the mall's IDENTITY is a handful of overridable methods on `DemoSeeder` and this class is the
 * only thing that changes. `DemoSeeder` still seeds exactly what it always did.
 *
 * ## Why it cannot be a rename after the fact
 *
 * The mall code is baked into every document number the run allocates — `LSE-VP-2026-0007`,
 * `INV-VP-0341`. Seeding as Atriom Walk and renaming the asset afterwards leaves every invoice,
 * lease and receipt carrying the previous mall's initials, on the page a client reads first. It has
 * to be set before the first document is allocated, which means before the seeder runs.
 *
 * ## What it deliberately does NOT do
 *
 * It does not set a tax registration. `ConfigurationHealth`'s `seller_tax_identity` row is BLOCKING
 * precisely so an install cannot issue a document titled *Tax Invoice* with no registration on it,
 * and a seeder that quietly satisfied that check would be answering on the operator's behalf. Run
 * `PlaceholderIssuerIdentitySeeder` for a test box, or set the real registration under Settings →
 * Tax — which is what a demo in front of a client should carry.
 *
 *     php artisan migrate:fresh --seed --seeder='Database\Seeders\ValPlazaSeeder'
 */
class ValPlazaSeeder extends DemoSeeder
{
    protected function primaryCode(): string
    {
        return 'VP';
    }

    protected function primaryName(): string
    {
        return 'Val Plaza';
    }

    protected function secondaryCode(): string
    {
        return 'VA';
    }

    protected function secondaryName(): string
    {
        return 'Val Annex';
    }

    /** The owner. Eltizam operates; Jawad owns. */
    protected function ownerName(): string
    {
        return 'Jawad';
    }

    protected function emailDomain(): string
    {
        return 'valplaza.test';
    }

    public function run(): void
    {
        // `--seeder=` runs ONE class, so the reference data `DatabaseSeeder` normally lays down
        // first has to be laid down here — otherwise the run dies partway through on a missing
        // role, having already written half a mall. Taken from that seeder's own list, never
        // re-typed.
        $this->call(DatabaseSeeder::REFERENCE);

        parent::run();

        // The terms `DemoSeeder` leaves empty — options, clauses, percentage-rent declarations and
        // CAM caps. Every one of those tabs renders on a lease and opens with nothing in it, and an
        // empty table reads as "not built" rather than "no data". Part of the demo, not a garnish.
        //
        // A command rather than a seeder because that is what it already is, and it is idempotent
        // per lease, so a second run adds nothing.
        Artisan::call('atriom:seed-leasing-depth');

        $this->command->newLine();
        $this->command->info('🏬 '.$this->primaryName().' is seeded.');
        $this->command->line('   Properties: '.Asset::whereIn('code', [$this->primaryCode(), $this->secondaryCode()])
            ->get()->map(fn (Asset $a) => $a->code.' = '.$a->name)->implode('  ·  '));
        $this->command->line('   Portal logins: tenant1@'.$this->emailDomain().' · staff1@'.$this->emailDomain());
        $this->command->newLine();
        $this->command->warn('   NOT set: the seller tax registration. Until it is, invoices are titled');
        $this->command->warn('   "Invoice" rather than "Tax Invoice" — deliberately, so an unconfigured');
        $this->command->warn('   install cannot issue a document a tenant would file with their accountant.');
        $this->command->line('   Set it under Settings → Tax, or seed a placeholder for a test box:');
        $this->command->line("   php artisan db:seed --class='Database\\Seeders\\PlaceholderIssuerIdentitySeeder'");
    }
}
