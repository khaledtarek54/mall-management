<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Does the annual escalation clause step the SERVICE CHARGE as well as the rent?
 *
 * The sweep and the ladder projection have only ever moved `base_rent_monthly`; the service charge
 * held its signing figure for the life of the lease unless an operator remembered to raise it by
 * hand through Change Rent — the exact revenue leak the escalation sweep was built to close for
 * rent. Egyptian mall leases routinely state one escalation for both ("the rent and service charge
 * shall increase by 7% annually"), and Yardi models this as PER-CHARGE escalation: any recurring
 * charge line can carry the step, not just the rent.
 *
 * A boolean, not a second rate: the common clause is "the same percentage on the same anniversary",
 * and a separate service-charge percentage is a term nobody has asked for. It applies only to the
 * percent-derived clause types (`fixed_percent`, `cpi`) — a step stated in POUNDS is a statement
 * about the rent, and adding the same EGP 5,000 to a service charge a fraction of its size charges
 * nobody what they agreed. That is the same reasoning that keeps the collar off `fixed_amount`,
 * and `Lease::escalatesServiceCharge()` is the one place it is stated.
 *
 * Defaults FALSE everywhere, so nothing an install bills moves on deploy — a lease escalates its
 * service charge only after somebody says so. Where the service charge is instead a reconciled CAM
 * estimate, the annual true-up already re-prices it and this toggle should stay off; escalating an
 * estimate the reconciliation corrects would double-adjust it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('escalation_applies_to_service_charge')
                ->default(false)
                ->after('escalation_interval_months');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('escalation_applies_to_service_charge');
        });
    }
};
