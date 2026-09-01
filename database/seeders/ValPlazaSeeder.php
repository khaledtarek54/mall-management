<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * VAL PLAZA, on its first day of trading — an empty mall with nothing on the books.
 *
 * ## What this is for
 *
 * Showing the system to the people who will run Val Plaza. `DemoSeeder` seeds a mall mid-life —
 * 33 leases, 290 invoices, 693 journal entries — which proves the system HOLDS a portfolio and is
 * exactly the wrong dataset for showing what an ACTION does, because every figure on every screen
 * was put there by somebody else.
 *
 * Here the trial balance opens empty. So the first lease created in the room is the first lease
 * that ever existed, the first invoice is the entire accounts receivable, and the entries that
 * appear in the general ledger are the ones the audience just watched being made. That is the
 * difference between believing the software works and seeing it work.
 *
 * ## What you get
 *
 *   - the reference data `atriom:install` lays down on a real first deploy — roles and permissions,
 *     the approval ladder, departments, the chart of accounts, the posting map, tax codes, charge
 *     codes and an open fiscal calendar. Without the accounting half a database bills perfectly and
 *     posts NOTHING, which would be the worst possible thing to discover mid-demo.
 *   - Val Plaza, with two floors and twelve VACANT units of varied size (per-m² rent and CAM
 *     pro-rata shares are only legible when the units differ)
 *   - three retailers with no lease between them — a leasing pipeline, not a rent roll
 *   - **no leases · no charges · no invoices · no payments · no journal entries**
 *
 * ## What it deliberately does NOT do
 *
 * It does not set a tax registration. `ConfigurationHealth`'s `seller_tax_identity` row is BLOCKING
 * so an install cannot issue a document titled *Tax Invoice* carrying no registration, and a seeder
 * that quietly satisfied that check would be answering on the operator's behalf. Set the real one
 * under Settings → Tax before showing an invoice to anybody.
 *
 *     php artisan migrate:fresh --seed --seeder='Database\Seeders\ValPlazaSeeder'
 *
 * Twelve units is a teaching size, not a claim about Val Plaza — widen `LearningSeeder::UNITS` if
 * the real estate should be on screen.
 */
class ValPlazaSeeder extends LearningSeeder
{
    protected function assetCode(): string
    {
        return 'VP';
    }

    protected function assetName(): string
    {
        return 'Val Plaza';
    }

    /** Eltizam operates; Jawad owns. */
    protected function ownerName(): string
    {
        return 'Jawad';
    }

    protected function emailDomain(): string
    {
        return 'valplaza.test';
    }
}
