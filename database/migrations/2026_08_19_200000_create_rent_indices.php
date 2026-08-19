<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The published index figures a CPI-linked lease escalates against.
 *
 * ## Yardi's construct, named
 *
 * Voyager's escalation schedule carries a **method** (fixed %, fixed amount, index/CPI, market
 * review), and for the index method an **index source**, a **publication lag** and a **base index
 * value** *(cited, `docs/benchmarks/yardi/01-yardi-lease-administration.md` §4)*. This table is the
 * index source: one row per published period, entered once by whoever reads the CAPMAS release,
 * and read by every CPI lease in the portfolio.
 *
 * ## Why a register rather than a feed
 *
 * There is no machine-readable Egyptian CPI feed to consume, and the module has always refused to
 * invent the number — correctly. A register does not invent anything: it records what was
 * published, with the date it was published on, so the figure a rent step used is auditable years
 * later when a tenant disputes it. That is the honest middle the gap analysis called for.
 *
 * ## PORTFOLIO-WIDE, deliberately
 *
 * An index is a national statistic. It carries no `asset_id` and is `#[PortfolioShared]` for the
 * same reason the chart of accounts is: a per-mall copy of a number published by the state is three
 * chances to key it differently.
 *
 * ## The unique key is (code, period)
 *
 * One value per index per month. A revision — statistical agencies do revise — is an EDIT to that
 * row, not a second row, because a lease that escalated on the old figure must be able to show
 * which figure it used and when it changed. `published_on` is what makes that visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_indices', function (Blueprint $table) {
            $table->id();
            // Short code the lease points at — 'EGY_CPI_URBAN', 'EGY_CPI_HEADLINE'. A string rather
            // than a foreign key to a catalogue: an operator tracks one or two indices, and a
            // second table to hold two rows is ceremony.
            $table->string('code', 32);
            // The month the figure DESCRIBES, always the first of that month. Distinct from
            // `published_on`, the day it became knowable — the gap between them is the publication
            // lag a lease has to allow for.
            $table->date('period');
            $table->decimal('value', 12, 4);
            $table->date('published_on')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['code', 'period']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_indices');
    }
};
