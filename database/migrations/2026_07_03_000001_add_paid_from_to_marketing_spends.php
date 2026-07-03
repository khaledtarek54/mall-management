<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_spends', function (Blueprint $table) {
            // Which asset account the money left (mirrors expenses.paid_from /
            // payrolls.paid_from — same enum) so a marketing spend can post
            // Dr Marketing Expense / Cr Cash|Bank to the GL.
            $table->enum('paid_from', ['cash', 'bank'])->default('cash')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_spends', function (Blueprint $table) {
            $table->dropColumn('paid_from');
        });
    }
};
