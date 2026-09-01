<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN ANCHOR MAY BE CARVED OUT OF THE DENOMINATOR — Yardi's *adjusted* basis.
 *
 * Yardi offers four denominators and Atriom shipped three: total GLA (the landlord eats the
 * vacancy), occupied area (the sitting tenants do), and a fixed stated figure. The fourth is
 * **adjusted** — "anchors carved out, in-line tenants share the rest" — and the benchmark records it
 * as having no equivalent here.
 *
 * It is the anchor deal every mall signs. The anchor negotiates a contribution its 3,000 m² would
 * never justify; without carving it out, its area sits in the divisor and dilutes every in-line
 * tenant's share, so the pool under-recovers by most of its value and the landlord silently absorbs
 * the difference. `stated_share_pct` alone gives the anchor its number and leaves that hole.
 *
 * `excluded_from_denominator` is a per-pool, effective-dated LEASE term, so it sits beside the cap,
 * the stated share and the account carve-out it is a sibling of. It is meaningless without a stated
 * share — a lease out of the divisor has no area basis left to derive one from — which the service
 * refuses rather than silently allocating it nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->boolean('excluded_from_denominator')->default(false)->after('stated_share_pct');
        });
    }

    public function down(): void
    {
        Schema::table('lease_cam_terms', fn (Blueprint $t) => $t->dropColumn('excluded_from_denominator'));
    }
};
