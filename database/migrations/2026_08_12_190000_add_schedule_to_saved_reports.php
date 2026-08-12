<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved report can deliver itself — the month-end pack lands in the owner's inbox.
 *
 * Nothing in this system emailed a report. The month-end pack was assembled by an operator opening
 * six screens, exporting six CSVs and attaching them to a mail, on a day they had to remember —
 * which means it arrived late in the months somebody was on leave, and not at all in the months
 * somebody left.
 *
 * The schedule lives on the saved report rather than in a table of its own: a schedule with no
 * parameters is not a thing, and a saved view has at most one delivery. Two tables would let them
 * disagree.
 *
 * ## What the columns say, and what they deliberately do not
 *
 * `frequency` + `day_of_month`/`day_of_week` is a deliberately small vocabulary. A cron expression
 * would be more general and is the wrong tool here: the operator setting this is an accountant
 * choosing "monthly, on the 3rd", and a system that accepts `0 6 3 * *` accepts `0 6 3 * ?` too.
 *
 * `last_delivered_on` is a DATE, not a timestamp, and it is the idempotency key. The command runs
 * from the scheduler and may run more than once in a day — a retry, a catch-up after downtime, two
 * workers — and a report that arrives three times is how an operator learns to filter the sender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_reports', function (Blueprint $table) {
            // null = not scheduled. The commonest state, and the one that must cost nothing.
            $table->string('frequency', 16)->nullable()->after('is_shared');
            $table->unsignedTinyInteger('day_of_month')->nullable()->after('frequency');
            $table->unsignedTinyInteger('day_of_week')->nullable()->after('day_of_month');

            // Who receives it. Plain addresses rather than user ids: a month-end pack routinely
            // goes to the owner's accountant and the auditor, neither of whom has a login here.
            $table->json('recipients')->nullable()->after('day_of_week');

            $table->date('last_delivered_on')->nullable()->after('recipients');

            $table->index(['frequency', 'last_delivered_on']);
        });
    }

    public function down(): void
    {
        Schema::table('saved_reports', function (Blueprint $table) {
            $table->dropIndex(['frequency', 'last_delivered_on']);
            $table->dropColumn(['frequency', 'day_of_month', 'day_of_week', 'recipients', 'last_delivered_on']);
        });
    }
};
