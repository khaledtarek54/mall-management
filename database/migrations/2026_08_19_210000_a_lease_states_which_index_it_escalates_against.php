<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three things a CPI clause needs beyond the collar it already has.
 *
 * *(cited, `docs/benchmarks/yardi/01-yardi-lease-administration.md` §4)* an index-method escalation
 * carries an **index source**, a **publication lag** and a **base index value**. Atriom had the
 * method (`escalation_type = 'cpi'`) and the collar (`escalation_floor_rate` /
 * `escalation_ceiling_rate`) and none of these, so the sweep could only skip.
 *
 * - `escalation_index_code` — WHICH index. Null on a CPI lease means the clause cannot be applied,
 *   and the sweep skips it rather than guessing at the portfolio's most-used index.
 * - `escalation_index_base_value` — the figure the NEXT step is measured from. It ROLLS FORWARD on
 *   each application, which is the compounding reading: Voyager offers compounding or
 *   applied-to-original-base as a choice, and this codebase already resolves that question one way
 *   — "a percentage step multiplies the current rent" — so a second, opposite convention for CPI
 *   would make two escalation types mean different things by the same word. Stated because it is a
 *   deliberate reading of an option, not the only possible one.
 * - `escalation_index_lag_months` — how far back to look. A September index published in October
 *   cannot drive a 1 January step unless the step reads the index from three months earlier.
 *   Default 0, which is "use the anniversary month itself".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('escalation_index_code', 32)->nullable()->after('escalation_ceiling_rate');
            $table->decimal('escalation_index_base_value', 12, 4)->nullable()->after('escalation_index_code');
            $table->unsignedTinyInteger('escalation_index_lag_months')->default(0)->after('escalation_index_base_value');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn([
                'escalation_index_code',
                'escalation_index_base_value',
                'escalation_index_lag_months',
            ]);
        });
    }
};
