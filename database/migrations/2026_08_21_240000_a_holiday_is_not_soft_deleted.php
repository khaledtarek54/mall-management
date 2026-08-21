<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `holidays` loses `deleted_at`, because soft-deleting one was a trap in three directions.
 *
 * The table carries `unique(asset_id, date)` and the index cannot see `deleted_at`. So:
 *
 *   1. Soft-delete a PROPERTY holiday, re-add the same date → integrity-constraint violation. A
 *      `QueryException` is not a `DomainException`, so the operator got the 500 page rather than a
 *      refusal they could read.
 *   2. Soft-delete a NATIONAL one → the unique index is inert for NULLs on both drivers, so
 *      `HolidaySeeder`'s `updateOrCreate` (which cannot see trashed rows) silently RESURRECTS it on
 *      the next install or reseed.
 *   3. Nothing offered a trashed filter or a restore, so a soft-deleted holiday was unreachable
 *      from every screen while still occupying its date.
 *
 * Adding `deleted_at` to the unique key does not fix it either — MySQL treats NULLs as distinct, so
 * a nullable column in the key would let live duplicates through, which is worse.
 *
 * The model was already `#[DeletionAllowed]`: nothing holds a foreign key to a holiday, deleting one
 * cannot re-time history (every deadline is stamped onto its work order with the clock it was
 * promised on), and deletion is super_admin-only project-wide. The screen guide tells operators to
 * DEACTIVATE rather than delete, and `is_active` is what does that. A second, invisible kind of
 * "gone" was never earning its place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
