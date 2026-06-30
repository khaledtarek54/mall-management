<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السنة المالية والفترات المحاسبية — Fiscal years and the months inside them.
 * Closing a period (status='closed') blocks backdated postings into it, which is
 * what makes a reported period trustworthy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique(); // e.g. 2026
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['open', 'closed'])->default('open'); // مفتوحة / مقفلة
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_no'); // 1..12 (الشهر المحاسبي)
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['open', 'closed'])->default('open'); // مفتوحة / مقفلة
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_no']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
