<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التخصصات — the trade register, and the end of "category is a translation string".
 *
 * ## What was wrong
 *
 * A work order's `category` — HVAC, plumbing, electrical — was a `Select` populated from
 * `__('admin.facility.categories')`, **a translation array**. Three consequences, none of which
 * ever presented as an error:
 *
 * 1. It was not in `App\Support\ValueSets`, so the column was **unenforced**: any string saved.
 * 2. The canonical list lived in `lang/en` and `lang/ar`, so an operator could not add a trade
 *    without a deploy — in a market where an operator adds trades — and the two files had to be
 *    kept in step by hand.
 * 3. **`vendors` had no trade at all** (only `type`: contractor/supplier/service_provider/…), so
 *    nothing could say Delta FM does HVAC. The vendor picker on an HVAC fault offered the
 *    stationery supplier, "spend by trade" had no dimension to group by, and `VendorScorecardService`
 *    compared a cleaning contractor with an HVAC contractor.
 *
 * ## The standard
 *
 * ServiceChannel makes the trade the spine of the model: it routes the work, decides which
 * providers are eligible, carries the SLA and is the axis every spend report groups by
 * (`docs/benchmarks/fm/02-servicechannel-contractor-loop.md` §2).
 *
 * ## Trade and craft are ONE register here, deliberately
 *
 * Maximo separates the **trade** (what the work is) from the **craft** (what a person is), and
 * carries the labour rate on the craft. In a mall those lists are the same list: an HVAC technician
 * does HVAC work. Two registers an operator has to keep in step would buy nothing at this scale, so
 * `standard_hourly_rate` lives here — which is also what the work-order cost object reads when it
 * turns reported hours into money. Split them the day a trade genuinely needs several rates
 * (a senior vs junior electrician), not before.
 *
 * ## `category` is DROPPED, not kept beside `trade_id`
 *
 * Two columns answering "what kind of work is this" is two truths about one question, and the
 * reader cannot tell which is current. The backfill matches on the code, which is exactly the
 * string the old column held.
 */
return new class extends Migration
{
    /**
     * The vocabulary the three consumers already shared, becoming rows.
     *
     * Order is the operator's own; codes are the EXACT strings the old columns held, so the
     * backfill is a join rather than a mapping table someone has to maintain.
     *
     * @var array<int, array{code: string, en: string, ar: string}>
     */
    private array $seed = [
        ['code' => 'hvac', 'en' => 'HVAC', 'ar' => 'التكييف والتهوية'],
        ['code' => 'electrical', 'en' => 'Electrical', 'ar' => 'الكهرباء'],
        ['code' => 'plumbing', 'en' => 'Plumbing', 'ar' => 'السباكة'],
        ['code' => 'elevator', 'en' => 'Elevator', 'ar' => 'المصاعد'],
        ['code' => 'fire-safety', 'en' => 'Fire safety', 'ar' => 'مكافحة الحريق'],
        ['code' => 'generator', 'en' => 'Generator', 'ar' => 'المولدات'],
        ['code' => 'structural', 'en' => 'Structural', 'ar' => 'الأعمال الإنشائية'],
        ['code' => 'cleaning', 'en' => 'Cleaning', 'ar' => 'النظافة'],
        ['code' => 'security', 'en' => 'Security', 'ar' => 'الأمن'],
        ['code' => 'safety', 'en' => 'Safety', 'ar' => 'السلامة'],
        ['code' => 'landscaping', 'en' => 'Landscaping', 'ar' => 'تنسيق الحدائق'],
        ['code' => 'pest_control', 'en' => 'Pest control', 'ar' => 'مكافحة الآفات'],
        ['code' => 'waste', 'en' => 'Waste management', 'ar' => 'إدارة المخلفات'],
        ['code' => 'other', 'en' => 'Other', 'ar' => 'أخرى'],
    ];

    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_en', 80);
            $table->string('name_ar', 80);

            // The craft rate — see the class docblock. NULLABLE on purpose: an operator who has
            // not set a rate gets NO labour cost on a job, which is visibly missing. A default
            // rate would produce a number that looks computed and is invented.
            $table->decimal('standard_hourly_rate', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('trades')->insert(array_map(fn (array $t, int $i): array => [
            'code' => $t['code'],
            'name_en' => $t['en'],
            'name_ar' => $t['ar'],
            'is_active' => true,
            'sort_order' => ($i + 1) * 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->seed, array_keys($this->seed)));

        // WHICH TRADES A VENDOR ACTUALLY DOES — the thing that made dispatch eligibility
        // inexpressible. Many-to-many because a facilities company does HVAC and electrical, and
        // pretending otherwise forces an operator to register one vendor twice.
        Schema::create('trade_vendor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['trade_id', 'vendor_id']);
        });

        foreach (['facility_work_orders', 'service_plans', 'equipment'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                // Nullable: a legacy row whose category was blank, or a value that never belonged
                // to the vocabulary (the column was unenforced, so that is possible), must survive
                // the migration rather than be assigned a trade nobody chose.
                $table->foreignId('trade_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });

            // Backfilled one code at a time rather than with `update … join`, which is MySQL-only
            // and would leave every SQLite test running against an unbackfilled table — i.e. green
            // here and wrong on the real database, the exact asymmetry `tests/Mysql/` exists for.
            foreach (DB::table('trades')->get(['id', 'code']) as $trade) {
                DB::table($t)->where('category', $trade->code)->update(['trade_id' => $trade->id]);
            }

            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        foreach (['facility_work_orders', 'service_plans', 'equipment'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('category', 32)->nullable()->after('id');
            });

            foreach (DB::table('trades')->get(['id', 'code']) as $trade) {
                DB::table($t)->where('trade_id', $trade->id)->update(['category' => $trade->code]);
            }

            Schema::table($t, function (Blueprint $table) {
                // Column name, not index name — Laravel derives the conventional constraint name,
                // and the FK must go before the column it covers.
                $table->dropForeign(['trade_id']);
                $table->dropColumn('trade_id');
            });
        }

        Schema::dropIfExists('trade_vendor');
        Schema::dropIfExists('trades');
    }
};
