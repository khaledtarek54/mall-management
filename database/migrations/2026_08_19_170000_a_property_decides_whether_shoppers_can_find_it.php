<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a property appears in the visitor app's mall list (module 36).
 *
 * Until now `GET /api/v1/public/malls` returned every ACTIVE property, so the only way to withhold
 * one was to deactivate it — which is not a publishing decision at all, it is an operational kill
 * switch that empties the switcher, hides the units and stops the billing screens. Publication and
 * operation were the same flag, and they are not the same question: a mall under fit-out is fully
 * operational and emphatically not something to advertise, and a decommissioned one may need to
 * stay visible while its last leases run out.
 *
 * The internal precedent is the argument. A STORE can already be withheld from the feed
 * (`tenants.is_listed`), and module 36 §9.5 deliberately stops a retail chain being mapped across
 * the portfolio from a public URL. The same instinct one level up was simply missing.
 *
 * **Defaults TRUE — listed — by the operator's decision (2026-08-19).** Nothing changes for anyone
 * on deploy: every mall a shopper can see today, they still see. The alternative was a safer
 * privacy posture that empties the feed until each property is opted in, and it was declined
 * because it turns a deploy into a content outage that has to be timed with the operator's staff.
 * The risk accepted, stated plainly: a property nobody intended to publish stays published until
 * somebody unticks it, and the form is where that is made visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_publicly_listed')
                ->default(true)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('is_publicly_listed');
        });
    }
};
