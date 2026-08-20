<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أكواد الأعطال — the reliability primitives, close-out step 5.
 *
 * ## The standard
 *
 * Maximo §7 records a failure as a HIERARCHY rather than a free-text note: failure class → problem
 * → cause → remedy. Recording all three on completion is what makes MTBF, bad-actor analysis
 * (which 5% of assets generate 40% of the work), repair-or-replace and warranty recovery
 * answerable at all.
 *
 * Scenario S6 is the failure it exists to catch: the same escalator handrail reported four times in
 * five weeks, four contractor visits, EGP 8,800 — and a register showing four unrelated successes,
 * because nothing recorded that all four were the same problem with four different remedies, which
 * is what "nobody has found the cause" looks like in data.
 *
 * ## Three levels, not four, and scoped by TRADE rather than chained
 *
 * Maximo's `class` is the asset's own failure class and its problems/causes/remedies form a chain:
 * these causes belong to that problem. **That chain is a matrix somebody has to populate before the
 * feature works at all** — and an unpopulated matrix offers no codes, so nobody records anything and
 * the primitive is dead on arrival.
 *
 * Here the trade IS the class — `App\Models\Trade` already classifies work orders, plans and
 * machines, and a second parallel taxonomy would be one more list to keep in step — and a code is
 * scoped to a trade rather than chained to a parent. An HVAC job is offered HVAC problems and does
 * not offer "lamp blown"; a code with **no** trade is offered everywhere, which is what makes a
 * useful starter set possible on day one. Stated as a deviation, not implied: revisit the chain if
 * the operator ever asks which causes belong to which problem, and not before.
 *
 * ## Optional at completion, deliberately
 *
 * Nothing is required. Switching a requirement on mid-flight refuses the next completion every
 * engineer attempts, and the reliable outcome is whatever code clears the validation fastest —
 * which is worse than a blank, because it looks like data. Same posture as
 * `SlaSettings::$require_completion_evidence` and straight-line rent. What IS surfaced is the
 * coverage, so an operator can see the gap rather than be told about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failure_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);

            // problem — what was observed ("no cooling")
            // cause   — why it happened ("refrigerant leak")
            // remedy  — what was done ("leak repaired, recharged")
            $table->string('type', 16);

            // Which trade offers this code. NULL = offered on every trade, which is what lets a
            // starter set exist before anyone has classified anything.
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // A code is unique within its TYPE, not globally: "leak" is a legitimate problem and a
            // legitimate cause, and forcing one row to serve both would make the pickers lie.
            $table->unique(['type', 'code'], 'failure_codes_type_code_unique');
            $table->index(['type', 'trade_id', 'is_active'], 'failure_codes_lookup_index');
        });

        /**
         * A deliberately SMALL, deliberately generic starter set — all trade-null, so every job
         * offers them from day one and the feature is not an empty picker nobody uses.
         *
         * It is a starting point the operator is expected to replace, **not a claim about their
         * business**. Maximo ships no codes at all and expects an operator to build the library;
         * shipping thirty invented Egyptian-mall codes would be exactly the guess this project
         * refuses elsewhere. Fifteen obvious ones, editable and deactivatable on the register.
         */
        $starter = [
            ['problem', 'not_working', 'Not working', 'لا يعمل'],
            ['problem', 'intermittent', 'Works intermittently', 'يعمل بشكل متقطع'],
            ['problem', 'noise', 'Unusual noise or vibration', 'صوت أو اهتزاز غير معتاد'],
            ['problem', 'leak', 'Leaking', 'تسريب'],
            ['problem', 'damage', 'Physical damage', 'تلف مادي'],
            ['cause', 'wear', 'Normal wear', 'استهلاك طبيعي'],
            ['cause', 'no_maintenance', 'Maintenance not carried out', 'عدم تنفيذ الصيانة'],
            ['cause', 'misuse', 'Misuse or accidental damage', 'سوء استخدام أو تلف عرضي'],
            ['cause', 'part_failure', 'Component failure', 'عطل في أحد المكونات'],
            ['cause', 'installation', 'Installation or design fault', 'خطأ في التركيب أو التصميم'],
            ['remedy', 'repaired', 'Repaired', 'تم الإصلاح'],
            ['remedy', 'part_replaced', 'Part replaced', 'تم استبدال قطعة'],
            ['remedy', 'adjusted', 'Adjusted or recalibrated', 'تمت المعايرة أو الضبط'],
            ['remedy', 'cleaned', 'Cleaned or serviced', 'تم التنظيف أو الخدمة'],
            ['remedy', 'no_fault_found', 'No fault found', 'لم يُعثر على عطل'],
        ];

        DB::table('failure_codes')->insert(array_map(fn (array $c, int $i): array => [
            'type' => $c[0],
            'code' => $c[1],
            'name_en' => $c[2],
            'name_ar' => $c[3],
            'trade_id' => null,
            'is_active' => true,
            'sort_order' => ($i + 1) * 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $starter, array_keys($starter)));

        Schema::table('facility_work_orders', function (Blueprint $table) {
            // Recorded on completion. Nullable throughout — see the class docblock.
            $table->foreignId('failure_problem_id')->nullable()->after('fault_notes')
                ->constrained('failure_codes')->nullOnDelete();
            $table->foreignId('failure_cause_id')->nullable()->after('failure_problem_id')
                ->constrained('failure_codes')->nullOnDelete();
            $table->foreignId('failure_remedy_id')->nullable()->after('failure_cause_id')
                ->constrained('failure_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facility_work_orders', function (Blueprint $table) {
            foreach (['failure_problem_id', 'failure_cause_id', 'failure_remedy_id'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn(['failure_problem_id', 'failure_cause_id', 'failure_remedy_id']);
        });

        Schema::dropIfExists('failure_codes');
    }
};
