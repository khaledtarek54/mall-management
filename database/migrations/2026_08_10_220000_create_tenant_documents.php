<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant compliance documents — above all the certificate of insurance (شهادة التأمين).
 *
 * Yardi tracks the tenant's insurance certificate and chases it before it lapses
 * (`06-atriom-gap-analysis.md` row 92). Atriom tracked this for **vendors** since module 12b and
 * not at all for tenants, which is the wrong way round if you only get one: a retailer trading
 * uninsured on the mall floor is the operator's liability, and almost every commercial lease
 * obliges the tenant to carry public-liability cover naming the landlord as an additional insured.
 * Today that obligation is written into the contract and then never checked again.
 *
 * Modelled as a general documents table from the outset rather than a single `coi_expires_at`
 * column, because module 12 already learned that lesson the expensive way — the vendor COI started
 * as two columns and had to be migrated into `vendor_documents` once the tax card and commercial
 * register needed the same chase. A tenant needs exactly those same Egyptian statutory papers
 * (بطاقة ضريبية, سجل تجاري) before they can be invoiced properly.
 *
 * Deliberately NOT a blocking gate. A lapsed vendor COI stops that vendor being dispatched, because
 * there is a dispatch decision to intercept. There is no equivalent for a sitting tenant — you
 * cannot un-let the shop because a policy expired — so this chases and surfaces, and the operator
 * acts. Inventing an automatic consequence here would be inventing a business rule nobody agreed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // String, not a DB enum (project convention) — the required set differs by jurisdiction
            // and must be extendable without a migration.
            $table->string('type');
            $table->string('reference')->nullable();   // policy / card / register number
            $table->string('issuer')->nullable();      // insurer or issuing authority
            $table->date('issued_on')->nullable();
            // Null = no expiry tracked: never chased. A commercial register with no renewal date on
            // file is a document we hold, not a document we nag about.
            $table->date('expires_on')->nullable();
            // What the policy actually covers. The certificate is only worth holding if the sum
            // insured is the one the lease demanded, and that is the number an operator compares.
            $table->decimal('coverage_amount', 14, 2)->nullable();
            $table->text('notes')->nullable();
            // The same two-column alert stamp the vendor chase uses: stage + the expiry it fired
            // for, so renewing a document re-arms its cycle by itself and a re-run never re-nags.
            $table->string('alert_stage')->nullable();
            $table->date('alert_for')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_documents');
    }
};
