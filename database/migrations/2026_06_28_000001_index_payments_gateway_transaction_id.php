<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Paymob S2S callback resolves the Payment by gateway_transaction_id on
     * every webhook (CallbackController::processed) — without an index that's a
     * full table scan of `payments` that worsens as volume grows. This is the
     * real-money hot path, so index it.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_transaction_id']);
        });
    }
};
