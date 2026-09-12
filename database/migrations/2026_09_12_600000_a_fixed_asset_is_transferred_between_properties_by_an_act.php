<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting 2026-09-02, point 18 — *"moving equipment from one place to another."*
 *
 * Until now the only way to move an asset between properties was to edit `fixed_assets.asset_id`,
 * which re-homed the WHOLE history: the acquisition entry and every depreciation entry were voided
 * and re-posted into the new property's dimension — restating months that may be closed, and
 * refused outright once one was. SAP's intra-company transfer (ABUMN) posts cost and accumulated
 * depreciation OUT of the old cost centre and IN to the new on the transfer date and leaves history
 * where it was; Yardi Fixed Assets transfers between properties the same way. So a transfer is a
 * dated ACT with a reason, and it posts TWO balanced entries — one per property — because every
 * financial statement here scopes on the entry's own property dimension (`je.asset_id`), and a
 * single entry can carry only one.
 *
 * `fixed_asset_transfers` is the act (from → to, when, why, by whom, the figures it moved) and
 * `fixed_asset_transfer_legs` are its two GL sources: the OUT leg in the old property, the IN leg in
 * the new. The net book value the OUT leg cannot balance on its own lands on the inter-property
 * clearing account (`inter_property_clearing`, seeded as `11801001`), which nets to zero across the
 * portfolio and states, per property, what one mall handed another.
 */
return new class extends Migration
{
    /**
     * Index names are STATED, not derived: Laravel's default for the legs' unique index
     * (`fixed_asset_transfer_legs_fixed_asset_transfer_id_direction_unique`, 66 chars) is over
     * MySQL's 64-character identifier limit, which sqlite does not enforce — the whole suite was
     * green and the first deploy of this migration died on the box mid-way, after both CREATEs
     * and before either index. Hence the `hasTable`/`hasIndex` guards: this migration re-runs on
     * that box against the tables it left behind, and on a fresh install creates them outright.
     */
    public function up(): void
    {
        if (! Schema::hasTable('fixed_asset_transfers')) {
            $this->createTransfers();
        } elseif (! Schema::hasIndex('fixed_asset_transfers', 'fa_transfers_asset_date_index')) {
            Schema::table('fixed_asset_transfers', fn (Blueprint $table) => $table->index(['fixed_asset_id', 'transferred_on'], 'fa_transfers_asset_date_index'));
        }

        if (! Schema::hasTable('fixed_asset_transfer_legs')) {
            $this->createLegs();

            return;
        }

        Schema::table('fixed_asset_transfer_legs', function (Blueprint $table) {
            if (! Schema::hasIndex('fixed_asset_transfer_legs', 'fa_transfer_legs_transfer_direction_unique')) {
                $table->unique(['fixed_asset_transfer_id', 'direction'], 'fa_transfer_legs_transfer_direction_unique');
            }
            if (! Schema::hasIndex('fixed_asset_transfer_legs', 'fa_transfer_legs_asset_date_index')) {
                $table->index(['asset_id', 'transferred_on'], 'fa_transfer_legs_asset_date_index');
            }
        });
    }

    private function createTransfers(): void
    {
        Schema::create('fixed_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('from_asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('to_asset_id')->constrained('assets')->restrictOnDelete();
            $table->date('transferred_on');
            // The figures the act moved, frozen: what the legs posted, never re-derived.
            $table->decimal('cost', 14, 2);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fixed_asset_id', 'transferred_on'], 'fa_transfers_asset_date_index');
        });
    }

    private function createLegs(): void
    {
        Schema::create('fixed_asset_transfer_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_transfer_id')->constrained('fixed_asset_transfers')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            // The property THIS leg's entry is dimensioned to — the old one on `out`, the new one on `in`.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('direction', 8); // out | in — App\Support\ValueSets
            $table->date('transferred_on');
            $table->decimal('cost', 14, 2);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fixed_asset_transfer_id', 'direction'], 'fa_transfer_legs_transfer_direction_unique');
            $table->index(['asset_id', 'transferred_on'], 'fa_transfer_legs_asset_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfer_legs');
        Schema::dropIfExists('fixed_asset_transfers');
    }
};
