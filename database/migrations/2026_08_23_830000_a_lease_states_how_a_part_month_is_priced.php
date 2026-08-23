<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a PARTIAL month is priced, as a lease term (EG-29 / M-1).
 *
 * Proration was one hardcoded line — days ÷ that month's own length — and that is one of the four
 * methods Yardi Voyager ships. Leases say different things: a clause reading "one thirtieth of the
 * monthly rent per day" is billed wrong in the seven months that do not have thirty days, on every
 * move-in, move-out, rent commencement and final cycle.
 *
 * **Nullable, and null is the normal state.** Null means "whatever the property says", which falls
 * through to the portfolio default of `actual` — exactly what every lease has been billed on since
 * this system existed. A column with a non-null default would have frozen today's method onto every
 * existing row and made the property tier unreachable, which is the mistake `charges.vat_applicable`
 * shipped with and EG-01 had to undo.
 *
 * The method belongs on the LEASE and not on the charge: a lease states one proration rule for the
 * money it governs, and no clause any of these malls has signed prorates rent one way and the
 * service charge another. If that is ever wrong it is a second column on the same seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Registered in `ValueSets`, so a mistyped method is refused on save rather than
            // silently taking the default the way an unconstrained column would.
            $table->string('proration_method', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('proration_method');
        });
    }
};
