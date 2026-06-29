<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convert invoice_items.type from a DB enum to a plain string. Allowed values now
 * live in App\Enums\InvoiceItemType (the single source of truth — validation +
 * Filament options), so adding a type (e.g. 'marketing') never needs another
 * lock-heavy enum ->change() migration again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        // Include 'marketing' in the restored enum: up() allowed it (string), so
        // marketing invoice items exist by now — omitting it would make down()
        // fail under MySQL strict mode (or lose those rows) on rollback/refresh.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->enum('type', [
                'base_rent', 'service_charge', 'utility', 'parking',
                'percentage_rent', 'marketing', 'late_fee', 'other',
            ])->change();
        });
    }
};
