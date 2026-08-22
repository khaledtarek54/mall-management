<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The standing wording on a tenant-facing document becomes a ROW (EG-15 slice 1, finding S-6).
 *
 * Every word on an invoice was a translation key, so changing the footer was a deploy. Two things
 * make that worse than the usual "it is in a lang file" complaint:
 *
 *   1. **The footer names payment rails.** `admin.pdf.footer` reads *"Payment due within :days days
 *      of issue · Bank transfer / Card / InstaPay"* — three rails, hardcoded, on the one document
 *      every tenant reads every month. Since EG-11 the rails are an operator catalogue they add to
 *      and retire, so the sentence can now be wrong the moment they use it.
 *   2. **No invoice shows bank details at all.** A tenant holding one has no way to know where to
 *      pay. There was nowhere to put it: the PDF has no such block, and no setting held the text.
 *
 * ## A row per key per property, and null means the portfolio
 *
 * `asset_id` nullable: one row is the house default, and a mall may override it. Bank details are
 * the case that forces this — two malls banking in two places is exactly the situation EG-12 built
 * `bank_accounts` for, and a single portfolio-wide payment instruction would tell half the tenants
 * to pay into the wrong account.
 *
 * ## Bilingual, as two columns rather than two rows
 *
 * The operator writes both languages in one form, and the resolver picks by the document's locale.
 * Two rows would let an install acquire an English footer with no Arabic one and no screen that
 * shows the gap.
 *
 * ## Plain text, deliberately — not a rich editor
 *
 * A DEVIATION from EG-15 as written, which asks for a `RichEditor`. Slice 1 is document BLOCKS —
 * a footer, terms, payment instructions — which are set in the document's own typography, so the
 * formatting a rich editor buys is mostly formatting the PDF will override. What it definitely
 * buys is operator-authored HTML flowing into mpdf and, later, into email, which is a real
 * escaping problem to take on for a bolded line. The rich editor belongs with the dunning/message
 * slice, where wording is the whole artefact. Line breaks are preserved; the text is escaped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();

            // Which block on which document. Constrained by `ValueSets` against
            // `App\Support\DocumentText::KEYS`, so a template cannot be written for a slot nothing
            // renders — the "settings screen that is inert" failure this project has shipped before.
            $table->string('key', 48);

            // Null = the portfolio default. A row with an asset overrides it for that mall only.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One row per block per property. Without this a second row is a silent tie the
            // resolver would break by insertion order, which is nobody's decision.
            $table->unique(['key', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
