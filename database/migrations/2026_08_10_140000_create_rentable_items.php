<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentable items — parking, storage, signage: let alongside a lease, but NOT lettable area.
 *
 * **Why this is not a `Unit`.** Yardi keeps two registers: spaces (lettable floor area, which
 * carries rent, the CAM share, occupancy and the stacking plan) and rentable items — garages,
 * carports, parking bays, storage rooms, signage — identified by their own code, assigned to a
 * lease, billed by their own charge code, and carrying **no leasable area**
 * (docs/benchmarks/yardi/09-yardi-space-and-parking.md).
 *
 * That separation is a MONEY rule, not a modelling preference. `Asset::totalUnitAreaSqm()` sums
 * every unit with no filter, so a parking bay stored as a `Unit` would grow the CAM denominator and
 * quietly cut every tenant's recovery share, report the mall as massively vacant (a car park is
 * never "occupied"), and make the rent roll's EGP/m²/yr meaningless. None of those would throw.
 * They would report the wrong number, which is this codebase's worst failure mode.
 *
 * A separate table makes the mistake structurally impossible rather than merely discouraged.
 *
 * **Generic on purpose.** Voyager's concept is "rentable items", not "parking" — a mall lets
 * storage rooms, signage and kiosk pitches on the same terms. `ParkingSpace` now would mean
 * `StorageUnit` later, which is the debt this shape avoids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentable_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            // Which zone it sits in, where that is meaningful (basement car park, roof signage).
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->string('code', 32);
            // String, not a DB enum — the house rule. Validated in the form.
            $table->string('type')->default('parking');
            $table->string('name')->nullable();

            // `available` | `assigned` | `out_of_service`. Derived in practice from the assignment,
            // but stored so an item can be taken out of service without a lease being involved.
            $table->string('status')->default('available');

            // The asking rate. The CONTRACTED rate lives on the lease's charge schedule — this is
            // the list price, the same way a unit has an asking rent.
            $table->decimal('monthly_rate', 12, 2)->default(0);

            // DELIBERATELY no area column. See the docblock: an area here is one join away from
            // being summed into GLA by a future report, and there is no legitimate reader for it.

            $table->string('notes', 1000)->nullable();
            // Fold-normalized search blob (App\Models\Concerns\HasSearchText) — an operator looks
            // for "P-042" or "مخزن", and both sides go through App\Support\Search\SearchText.
            $table->text('search_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'code']);
            $table->index(['asset_id', 'status']);
        });

        // The assignment: dated, exactly like `lease_unit`, so a lease that takes two more bays in
        // March is expressible and the money can follow the date rather than "now".
        Schema::create('lease_rentable_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rentable_item_id')->constrained()->restrictOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            // What this lease actually pays for it — the negotiated figure, which may differ from
            // the item's asking rate.
            $table->decimal('monthly_rate', 12, 2)->default(0);
            $table->timestamps();

            // An item can be re-let after release, so the key includes the start date rather than
            // being one row per (lease, item) for ever.
            $table->unique(['lease_id', 'rentable_item_id', 'effective_from'], 'lease_item_from_unique');
            $table->index('rentable_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_rentable_item');
        Schema::dropIfExists('rentable_items');
    }
};
