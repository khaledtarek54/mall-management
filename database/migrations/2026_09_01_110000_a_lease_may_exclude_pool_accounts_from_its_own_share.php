<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LEASE MAY EXCLUDE POOL ACCOUNTS FROM ITS OWN SHARE (slice 3).
 *
 * `cam_allocations.exclusions` has existed since the table was created — fillable, cast to array,
 * and read by NOTHING: no service, no form, no report. Present and inert, which module 08 has
 * carried in its own gap list as "still unused" for the whole of its life.
 *
 * What was missing is the TERM. An exclusion is a clause in ONE tenant's lease ("my share excludes
 * capital items and the management fee"), so it belongs beside the cap and the stated share on
 * `lease_cam_terms` — which since 2026-09-01 is already keyed by (lease, pool, year), and a clause
 * that excludes the food-court grease trap from a CAM share would be meaningless without that.
 *
 * `excluded_account_ids` is a JSON list of `ledger_accounts.id`, resolvable only on a pool sourced
 * FROM the ledger — a pool whose total was typed has no accounts to exclude anything from, which
 * the service states rather than silently allocating in full.
 *
 * `cam_allocations.excluded_amount` is what was actually taken off, recorded on the allocation
 * because `landlord_unrecovered_amount` is `actual − Σ allocated` and would otherwise absorb it
 * unlabelled — reported as vacancy, which is a different decision with a different lever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->json('excluded_account_ids')->nullable()->after('stated_share_pct');
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->decimal('excluded_amount', 14, 2)->default(0)->after('allocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('lease_cam_terms', fn (Blueprint $t) => $t->dropColumn('excluded_account_ids'));
        Schema::table('cam_allocations', fn (Blueprint $t) => $t->dropColumn('excluded_amount'));
    }
};
