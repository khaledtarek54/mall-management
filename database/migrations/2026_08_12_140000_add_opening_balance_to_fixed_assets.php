<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a fixed asset that predates this system be loaded at cut-over.
 *
 * A mall arriving on Atriom already owns chillers, escalators and generators bought years ago. Two
 * things go wrong if they are simply created as ordinary assets:
 *
 *  - **The acquisition posts.** `FixedAssetAcquisitionJournalizer` writes
 *    `Dr Furniture & Equipment / Cr Cash|Bank` dated `acquisition_date`. For a 2023 purchase that
 *    either lands in a closed period (refused, and stranded inside the best-effort sync job) or
 *    double-counts cost the accountant's opening journal entry already carries.
 *  - **It depreciates from zero.** `accumulatedFor()` sums `depreciation_entries`, and a legacy
 *    asset has none — so a chiller three years into a ten-year life would charge its FULL cost
 *    again over another ten years, and the balance sheet would carry it at cost.
 *
 * `is_opening_balance` answers the first: the journalizer returns null, exactly as
 * `invoices.is_opening_balance` does, because the revenue/cost is already in the opening entry.
 *
 * `opening_accumulated_depreciation` answers the second, as a FIGURE rather than a synthetic
 * `DepreciationEntry`. A fake monthly charge would have to be dated somewhere, would show up in the
 * depreciation register as a month that never happened, and would post its own
 * `Dr Depreciation / Cr Accumulated` — needing a second suppression flag to undo. One column on the
 * asset says the same thing and lies to nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('status');
            $table->decimal('opening_accumulated_depreciation', 14, 2)->default(0)->after('is_opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['is_opening_balance', 'opening_accumulated_depreciation']);
        });
    }
};
