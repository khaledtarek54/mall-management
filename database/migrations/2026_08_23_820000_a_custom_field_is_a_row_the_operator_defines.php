<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-defined fields — the largest structural gap this system had against the market standard
 * (D-7 / EG-32).
 *
 * Yardi has UDFs, MRI has user-defined fields, Odoo has Studio. Every operator eventually needs to
 * record something the vendor never modelled: the tenant's parent group, the lease's broker, the
 * unit's landlord-works reference, whether a supplier is on the government's approved list. Without
 * somewhere to put it the information ends up in the notes field, where nothing can filter, report
 * or export it — or the operator asks for a deploy, and pays for one, every time.
 *
 * ## The storage was already here, and read by nothing
 *
 * `tenants`, `leases`, `assets`, `vendors` and `departments` have carried a nullable `metadata`
 * JSON column since the first migrations. All five are `fillable`, all five are cast to `array`, and
 * **not one of them is written or read by any form, table, service, report or export.** The audit
 * that raised D-7 counted them as evidence of the gap; they are also its answer. A value lives on
 * the record it describes, which means no join, no N+1, and an export is a column read rather than
 * a second query per row.
 *
 * **`units` gains one here**, and it is the only host-table change. The shop is the record a mall
 * accumulates the most of its own physical facts about — shutter type, grease-trap access, the
 * landlord-works reference — and it was the one master record without somewhere to put them.
 * (`units.features` was a different thing, a fixed amenity list, and `2026_08_10_170000` dropped it
 * as unused.) `departments` keeps its column and is deliberately NOT offered: an internal org unit
 * is not what an operator extends, and if that proves wrong it is one line in the register, because
 * the storage is already there.
 *
 * ## What a row is
 *
 * `model` names WHICH record type carries the field, as a **morph alias** — the same vocabulary
 * `App\Support\MorphMap` already governs, which refuses an unmapped class on read as well as write.
 * Never a FQCN: a namespace move would orphan every definition, which is the reasoning
 * `saved_reports` and `table_views` already use for storing a slug rather than a class name.
 *
 * `key` is the JSON key the value is stored under. It is unique **per model**, not globally: two
 * record types may both sensibly have a `parent_group`, and forcing one to be `tenant_parent_group`
 * would be this system's naming leaking into the operator's vocabulary.
 *
 * ## Why the key is immutable once it exists
 *
 * The key IS the address of every value already recorded. Renaming it would strand them — the data
 * would still be in `metadata` under the old key and nothing would ever read it again. The label is
 * what the operator renames, in both languages, and that reaches every record at once because a
 * label is resolved at READ time. Same rule as the activity log: the row stores DATA, the words come
 * later.
 *
 * ## Deactivating is not deleting
 *
 * `is_active` stops a field being OFFERED on the form; it never removes a value already recorded.
 * A field retired half way through a year still explains what is on the records that carry it, so
 * the display keeps showing it — exactly as a retired charge code still labels the invoice lines it
 * raised.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The one host table without somewhere to keep the operator's own facts — see above.
        if (! Schema::hasColumn('units', 'metadata')) {
            Schema::table('units', function (Blueprint $table) {
                $table->json('metadata')->nullable();
            });
        }

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            // A MORPH ALIAS ('tenant', 'lease', …), never a class name. MorphMap owns the vocabulary.
            $table->string('model', 64);

            // The JSON key inside the record's `metadata`. Immutable once rows carry values.
            $table->string('key', 64);

            $table->string('label_en', 96);
            $table->string('label_ar', 96);

            // text · textarea · number · date · select · boolean — registered in ValueSets.
            $table->string('type', 32)->default('text');

            // The choices, for `select` only. Each entry carries its own two labels, because a
            // dropdown an Arabic-speaking operator reads must not fall back to English.
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Unique PER MODEL — see the class docblock.
            $table->unique(['model', 'key']);

            // The one query the form asks: "what does this record type carry, in what order".
            $table->index(['model', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        // Only the definitions. Values live in each record's own `metadata` and are deliberately
        // left alone — dropping the catalogue must not silently strip data off the records, and a
        // definition re-created with the same key finds its values still there. `units.metadata`
        // stays for that reason: reversing this migration should cost the operator no data.
        Schema::dropIfExists('custom_fields');
    }
};
