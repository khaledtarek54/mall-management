<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account lifecycle: an operator can suspend a login without destroying it.
 *
 * Until now the only way to stop someone signing in was to delete the user — which takes their
 * name off every record they touched and off the activity log with it. Every competitor system
 * (and every auditor) expects a leaver's account to be *disabled and kept*, so the history stays
 * attributable.
 *
 * A plain string, not a DB enum, per the project convention — the value set is validated in
 * PHP (App\Models\User::STATUSES) so adding "locked" later is a code change, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->index()->after('email');
            // Who turned it off and when — the two questions an audit asks first.
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'suspended_at', 'suspended_reason']);
        });
    }
};
