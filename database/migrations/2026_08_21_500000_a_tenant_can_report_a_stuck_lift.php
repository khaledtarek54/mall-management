<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a tenant may report, as rows — and LINKED to the trade register rather than matched to it.
 *
 * `TenantRequestType::subcategories()` was a `match()` returning seven maintenance values;
 * `trades` seeds fourteen. `RaiseCorrectiveWorkOrderService::tradeForRequest()` bridged the two by
 * comparing `tenant_requests.category` against `trades.code` — a string match between two lists
 * nothing kept in step. The consequence was not subtle: **a tenant could not report a stuck lift, a
 * generator failure, a fire-safety fault, a pest problem, a security issue, a landscaping fault or
 * a waste problem** as such. They picked "other" or "safety", and the work order was raised with no
 * trade, so it was invisible to every by-trade report and to vendor eligibility.
 *
 * A foreign key instead of a string match is the whole fix: the two registers cannot drift again,
 * and a code mismatch stops mattering — the `fire_safety` subcategory can point at the `fire-safety`
 * trade, which under the old scheme would silently have resolved to nothing.
 *
 * **The TYPE stays a PHP enum.** It carries behaviour — `requiresDecision()`, `allowsScheduling()`,
 * `referencePrefix()`, `defaultDepartmentSlug()` — and CLAUDE.md's rule is that an enum is the
 * better shape where one exists, because the model casts against it and the value-set listener
 * refuses against it, so the two cannot drift. Rows would let an operator create a type the code has
 * no answers for. Only the VOCABULARY moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_request_subcategories', function (Blueprint $table) {
            $table->id();

            // Which type this belongs under. A string, matching the enum's backed value — the enum
            // is the authority and this points at it, never the reverse.
            $table->string('request_type', 32);

            // The value stored on `tenant_requests.category`.
            $table->string('code', 40);

            $table->string('name_en', 64);
            $table->string('name_ar', 64);

            // The trade this problem belongs to, when it is a maintenance fault. NULL for everything
            // else: a noise complaint, a lease copy and a parking pass are not trades, and copying
            // the category across as one is what put `noise` and `lease_copy` in the trade column
            // for the whole of module 26's life.
            $table->foreignId('trade_id')->nullable()->constrained('trades')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // A code is unique WITHIN a type, not globally: `other` is a legitimate subcategory of
            // four different types and always has been.
            // Named explicitly: the auto-generated names exceed MySQL's 64-character identifier
            // limit for a table called `tenant_request_subcategories`, and sqlite accepts them —
            // so the suite would have stayed green while the real migration refused.
            $table->unique(['request_type', 'code'], 'trs_type_code_unique');
            $table->index(['request_type', 'is_active', 'sort_order'], 'trs_type_active_sort_index');
        });

        // Per-TYPE SLA hours, as a dimension on the register that already answers this question per
        // property. `TenantRequestType::slaHours()` was a second hardcoded map — maintenance 4/24/72/168,
        // complaint 8/48/96/168, access 4/24/48/96 — with its own docblock conceding "Phase 2 reads
        // these from settings/the request_types table". NULL means "any type", which is what every
        // existing row means and what module 26's work orders need, since they have no request type.
        // NOT NULL, with an explicit `any` sentinel — NOT nullable.
        //
        // A nullable column inside a UNIQUE stops enforcing it: SQL treats NULLs as DISTINCT, so
        // `unique(asset_id, request_type, priority)` with two NULL types happily accepts two
        // conflicting "urgent" policies at one property, and the resolver then picks whichever the
        // index returns first. Measured, not reasoned: the first cut of this migration accepted the
        // duplicate and the existing uniqueness test went green because its expected exception
        // stopped being thrown. A sentinel keeps the constraint real on both drivers.
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->string('request_type', 32)->default('any')->after('asset_id');
        });

        // The uniqueness has to GROW with the new dimension. `unique(asset_id, priority)` would make
        // "urgent maintenance in 4 hours, urgent complaint in 8" impossible to express at one
        // property — the second row would collide with the first, and the operator would see a
        // duplicate-key 500 with nothing explaining which row it clashed with.
        // ADD BEFORE DROP, deliberately. MySQL keeps `sla_policy_asset_priority_unique` as the
        // index backing the `asset_id` foreign key, and refuses to drop it while it is the only one
        // that can serve — "Cannot drop index … needed in a foreign key constraint". Creating the
        // wider unique first gives the constraint a replacement, and then the old one goes.
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->unique(['asset_id', 'request_type', 'priority'], 'sla_policy_asset_type_priority_unique');
        });

        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropUnique('sla_policy_asset_priority_unique');
        });
    }

    public function down(): void
    {
        // Same ordering in reverse, for the same reason.
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->unique(['asset_id', 'priority'], 'sla_policy_asset_priority_unique');
        });

        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropUnique('sla_policy_asset_type_priority_unique');
            $table->dropColumn('request_type');
        });

        Schema::dropIfExists('tenant_request_subcategories');
    }
};
