<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lease abstract — the legal terms that do not reduce to money.
 *
 * ## The benchmark's own list, and its own reason
 *
 * *(cited, `docs/benchmarks/yardi/01-yardi-lease-administration.md` §7)* the abstract is "a
 * structured record of the legal terms that don't reduce to money": use · **exclusivity** ·
 * **radius restriction** · **co-tenancy** · **kick-out** · assignment and subletting consent ·
 * insurance requirements · operating hours and continuous operation · signage · parking allocation ·
 * repair obligations · guarantor.
 *
 * And the reason an ERP must hold them rather than leave them in the PDF, quoted because it is the
 * whole justification for this table:
 *
 * > "co-tenancy and kick-out clauses are *contingent money*. … In Atriom these clauses live only in
 * > the uploaded PDF, so nothing can act on them and nothing can even report 'how many of our
 * > leases have a co-tenancy trigger tied to the anchor we are about to lose'."
 *
 * Being able to ANSWER that question is the deliverable. Acting on it automatically is not — see
 * the model.
 *
 * ## Why typed columns rather than a JSON blob
 *
 * Most of these clauses are prose, but four carry a NUMBER that the business actually reasons
 * about: the occupancy floor a co-tenancy abatement triggers at, the sales threshold a kick-out
 * needs, the kilometres a radius restriction covers, and the notice a right requires. A number
 * buried in JSON cannot be filtered, compared or reported on, which puts it back where it started —
 * in prose nobody can query. The remaining clause types simply leave them null.
 *
 * ## Dated, because a clause can lapse
 *
 * A co-tenancy protection commonly runs for the first N years only, and a radius restriction can
 * outlive the term. `applies_from` / `applies_to` bound it; null on either side is open-ended, the
 * same convention the charge schedule and the premises pivot use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();

            // A string, not a DB enum — house rule. The set lives in `App\Support\ValueSets`, which
            // the wildcard saving listener refuses out-of-set values against on every model.
            $table->string('type', 32);

            // What the clause says, in the operator's words. The PDF stays the source of truth for
            // the wording; this is the abstract, which is a different thing and is allowed to be
            // shorter than the clause it summarises.
            $table->text('summary')->nullable();

            // The four numbers the business reasons about. Null on the clause types that carry none.
            $table->decimal('threshold_pct', 5, 2)->nullable();
            $table->decimal('threshold_amount', 14, 2)->nullable();
            $table->decimal('radius_km', 8, 2)->nullable();
            $table->unsignedSmallInteger('notice_days')->nullable();

            $table->date('applies_from')->nullable();
            $table->date('applies_to')->nullable();

            // Where it is in the contract, so somebody can find the wording without reading 60
            // pages. Free text: "cl. 14.3", "Schedule 2 §4" — contracts do not agree on a scheme.
            $table->string('source_reference', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['lease_id', 'type']);
            // The portfolio question the benchmark names — "which leases carry a co-tenancy
            // trigger?" — is a scan by type, so it gets its own index.
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_clauses');
    }
};
