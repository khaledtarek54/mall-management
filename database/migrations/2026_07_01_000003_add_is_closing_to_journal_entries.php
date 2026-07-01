<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags year-end closing entries (قيود الإقفال). They zero the revenue/expense
 * accounts into retained earnings, so they must be EXCLUDED from the income
 * statement (which should show the year's actual P&L) but INCLUDED in the trial
 * balance and balance sheet (where the P&L accounts should read zero post-close).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_closing')->default(false)->after('is_manual');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('is_closing');
        });
    }
};
