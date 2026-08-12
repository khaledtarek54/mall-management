<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each operator last chose on each report (RP-02).
 *
 * Eltizam runs several malls, and an accountant who works one of them re-picked it on every report,
 * every time. That is the standing preference this remembers.
 *
 * **Dates are deliberately NOT remembered** — see `App\Support\ReportPreferences::VOLATILE` for the
 * reasoning, which is the whole design of this table rather than a detail of it.
 *
 * Per USER, not per role or per install: two accountants covering different malls must not fight
 * over one stored value, which is what a shared preference would make them do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The page class. A string rather than a foreign key because reports are code, not rows.
            $table->string('report');
            $table->json('parameters');

            $table->timestamps();

            // One remembered set per user per report. Two rows would make "the preference" depend on
            // which the query happened to return first.
            $table->unique(['user_id', 'report']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_preferences');
    }
};
