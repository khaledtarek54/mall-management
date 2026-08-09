<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag ONE invoice line as disputed (story MF-07).
 *
 * **Why the line and not the invoice.** `invoices.status` already has a `disputed` value, and it is
 * the wrong tool for this: an invoice is rarely disputed in full. The argument is about the service
 * charge, while the rent on the same document is undisputed and collectable — marking the header
 * disputed stops chasing money nobody is arguing about, and marking nothing charges a late fee on
 * money nobody has agreed is owed. So the flag lives on the line and the header status is untouched.
 *
 * The reason is NOT optional in the service: "disputed" with no stated reason is a note to nobody,
 * and this flag suppresses a fee — it has to say why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->timestamp('disputed_at')->nullable()->after('total');
            $table->string('disputed_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by_id')->nullable()->after('disputed_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disputed_by_id');
            $table->dropColumn(['disputed_at', 'disputed_reason']);
        });
    }
};
