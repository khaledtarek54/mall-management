<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tiny durable key-value store for system/operational state — e.g. when the
 * ledger sweep (accounting:sync-ledger) last ran, so the accounting screens can
 * show a trustworthy "last synced" indicator that survives cache clears/deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
