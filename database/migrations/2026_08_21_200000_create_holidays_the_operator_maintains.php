<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days the mall's people are not at work — announced, so a table and never a formula.
 *
 * Egypt has ~15 public holidays a year and **they cannot be computed**. The two Eids move on the
 * Hijri calendar and are fixed by moon sighting a day or two ahead; since 2020 the government
 * routinely shifts a mid-week holiday to the Thursday beside it. Any library that claims to know
 * next year's Eid is guessing. So the operator keeps the list, one row at a time, and the only
 * honest thing the system can do is ask.
 *
 * ## Two kinds, and the second one is Ramadan
 *
 *   - `closure` — nobody is at work. The SLA clock does not run.
 *   - `short_day` — a working day with different hours. Ramadan shortens the day to six by law and
 *     it is paid in full, so a 24-hour SLA raised in Ramadan spans four days, not three.
 *
 * There is deliberately no third kind for "an exceptional working Friday". A mall that trades
 * through Eid is the ABSENCE of a row for that property, not the presence of a special one —
 * `asset_id` is checked before the portfolio-wide rows, so a property can already say "not us".
 *
 * ## `asset_id` is nullable, and null is the normal case
 *
 * A national holiday is one row for the whole portfolio (`#[PropertyOwned(portfolioRowsWhenNull:
 * true)]`, the shape `vendor_contracts` and `Department` already use). A property-specific row —
 * a mall closed for a fit-out day — wins over the national one for that date.
 *
 * The unique index cannot enforce "one national row per date" on MySQL, which treats NULLs as
 * distinct. That is accepted rather than worked around: a duplicate national holiday is harmless
 * (the resolver asks *whether* a row exists, not how many), and the alternatives — a sentinel
 * `asset_id = 0`, or a generated column — would each be a second way to say "portfolio-wide".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            // Null = every property. A row for one mall wins over the national one on that date.
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('kind', 16)->default('closure');

            // Only meaningful for `short_day`; a closure has no hours by definition.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'date'], 'holidays_asset_date_unique');
            $table->index(['date', 'is_active'], 'holidays_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
