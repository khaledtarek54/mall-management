<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permit to work — the operator's written authorisation for hazardous physical work.
 *
 * ## Not a Yardi construct, and said so
 *
 * Voyager does not model this: it is lease-administration software, and a permit to work belongs to
 * facilities management. The benchmark folder contains **zero** hits for hot work, isolation or
 * permit-to-work, so this follows the FM/CMMS standard (ServiceChannel, Facilio, Maximo all treat
 * it as core) and long-standing safety practice, rather than pretending to a Yardi lineage. Flagged
 * as an EXTENSION under the project's own rule: name the construct or admit the invention.
 *
 * ## Why a mall operator needs it
 *
 * A contractor cutting or welding in a plant room, isolating a panel, or working above a trading
 * floor is a risk the operator carries whether or not anyone wrote it down. The control that the
 * whole industry uses is a permit: a named person authorises specific work, in a specific place,
 * for a specific WINDOW, under stated conditions — and somebody closes it out afterwards confirming
 * the area was left safe.
 *
 * ## The two properties that make it a control rather than a form
 *
 * 1. **It is time-bounded to the hour.** `valid_from`/`valid_to` are datetimes, not dates: "hot work
 *    permitted on Tuesday" is not a permit, "hot work permitted 09:00–13:00 Tuesday" is. A permit
 *    good for a whole day is one somebody uses at 19:00 when the fire officer has gone home.
 * 2. **It must be CLOSED, and an unclosed expired permit is the finding.** Work authorised and never
 *    signed off is the state a safety audit looks for, because it means nobody confirmed the
 *    welding stopped and the area was checked. That is why `closed_at` exists separately from the
 *    window ending, and why the sweep reports the gap between them.
 *
 * ## Separate from the tenant's fit-out permit, deliberately
 *
 * `TenantRequestType::Permit` is a TENANT asking permission (fit-out, signage) through the portal.
 * This is the OPERATOR authorising a contractor — often its own vendor, with no tenant involved at
 * all. Folding them together would make a safety control lease-shaped, which is the same mistake
 * that keyed rentable items to a lease.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32)->unique();

            // hot_work · electrical_isolation · working_at_height · confined_space · excavation ·
            // general. A string with the set in ValueSets — no DB enums, house rule.
            $table->string('type', 32);
            $table->string('status', 32)->default('draft');

            // WHO is doing the work. A registered vendor where there is one; free text as well,
            // because the person on site is a named individual and "Delta FM" is not who you stop
            // if something is wrong.
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contractor_name', 120)->nullable();
            $table->string('contractor_phone', 40)->nullable();

            // WHAT job it covers, and WHERE. All optional links — a permit can precede the work
            // order, and plant rooms belong to a zone rather than a unit.
            $table->foreignId('facility_work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 160)->nullable();

            $table->text('description');

            // The controls imposed as a condition of the grant — fire watch, gas test, isolation
            // certificate, hours. Free text on purpose: the conditions on a confined-space entry
            // and on a signage installation share no schema, and a checklist invented here would
            // be a checklist nobody's safety officer agreed to.
            $table->text('conditions')->nullable();

            // To the HOUR — see the class docblock.
            $table->dateTime('valid_from');
            $table->dateTime('valid_to');

            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->text('closure_notes')->nullable();

            // Fold-normalized search blob (App\Models\Concerns\HasSearchText) — a permit
            // reference is quoted at a gate and on the radio, and a contractor is hunted for by
            // name in both spellings, so both sides go through App\Support\Search\SearchText.
            $table->text('search_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'status']);
            // The audit question — "what is authorised right now?" and "what expired without being
            // closed?" — both scan the window, so it is indexed.
            $table->index(['status', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_permits');
    }
};
