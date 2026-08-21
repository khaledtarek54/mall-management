<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 11 gets the same frozen SLA clock module 26 has (EG-38).
 *
 * `SlaSettings` is shared — its own docblock says so — so `sla_working_clock_priorities` was being
 * honoured by work orders and silently ignored by tenant requests. An operator ticking `medium`
 * therefore got two different SLA semantics for the same priority depending on whether the ticket
 * arrived as a request or as a work order, which is precisely the split the maintenance rename was
 * done to end.
 *
 * Frozen and nullable for the same reasons as `facility_work_orders.sla_clock`: resolving at read
 * time would re-time a request already running, a NOT-NULL default would make the `??=` never fire,
 * and null is the honest value for every request raised before the calendar existed. No backfill.
 *
 * Note module 11 has only ONE clock — there is no response target here, so nothing to pair it with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->string('sla_clock', 16)->nullable()->after('target_resolution_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropColumn('sla_clock');
        });
    }
};
