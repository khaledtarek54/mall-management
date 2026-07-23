<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor compliance is more than one insurance date (module 12b).
 *
 * `vendors.coi_expires_at` modelled exactly ONE document. Before an Egyptian entity may legally
 * engage and pay a supplier it needs several, each with its own expiry and its own renewal chase:
 * بطاقة ضريبية (tax card), سجل تجاري (commercial register), and for contractors شهادة تأمينات
 * اجتماعية (social-insurance certificate — the principal carries liability for a subcontractor's
 * unpaid social insurance). Insurance itself is rarely one policy either.
 *
 * Rather than bolt a second mechanism alongside the COI columns — two sources of truth for one
 * concept, which is exactly how alerting drifts apart — this MOVES the COI into a general
 * documents table and drops the columns. `vendors.coi_expires_at` and its alert stamps cease to
 * exist; `Vendor::isDispatchable()` now reads the blocking documents instead.
 *
 * Existing COI files are re-pointed, not re-uploaded: medialibrary keys the stored file by media
 * id, so switching model_type/model_id/collection_name moves the attachment intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // String, not a DB enum (project convention) — the document set differs by
            // jurisdiction and must be extendable without a migration.
            $table->string('type');
            $table->string('reference')->nullable();   // policy / card / register number
            $table->string('issuer')->nullable();      // insurer or issuing authority
            $table->date('issued_on')->nullable();
            // Null = no expiry tracked: never blocks, never chased.
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            // Same two-column alert stamp as the COI chase it replaces: stage + the expiry date
            // it fired for, so renewing a document re-arms its alert cycle by itself.
            $table->string('alert_stage')->nullable();
            $table->date('alert_for')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'type']);
            $table->index('expires_on');
        });

        // ---- Backfill: every recorded COI becomes an insurance document ----
        DB::table('vendors')
            ->whereNotNull('coi_expires_at')
            ->orderBy('id')
            ->each(function ($vendor) {
                $documentId = DB::table('vendor_documents')->insertGetId([
                    'vendor_id' => $vendor->id,
                    'type' => 'insurance_coi',
                    'reference' => $vendor->policy_number,
                    'issuer' => $vendor->insurer,
                    'expires_on' => $vendor->coi_expires_at,
                    'alert_stage' => $vendor->coi_alert_stage ?? null,
                    'alert_for' => $vendor->coi_alert_for ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Carry the certificate file across intact.
                DB::table('media')
                    ->where('model_type', 'App\Models\Vendor')
                    ->where('model_id', $vendor->id)
                    ->where('collection_name', 'coi')
                    ->update([
                        'model_type' => 'App\Models\VendorDocument',
                        'model_id' => $documentId,
                        'collection_name' => 'file',
                    ]);
            });

        Schema::table('vendors', function (Blueprint $table) {
            // SQLite refuses to drop a column an index still references, so the index goes first.
            $table->dropIndex(['coi_expires_at']);
            $table->dropColumn(['coi_expires_at', 'insurer', 'policy_number', 'coi_alert_stage', 'coi_alert_for']);
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->date('coi_expires_at')->nullable();
            $table->string('insurer')->nullable();
            $table->string('policy_number')->nullable();
            $table->string('coi_alert_stage')->nullable();
            $table->date('coi_alert_for')->nullable();
        });

        // Fold the latest insurance document back onto the vendor, and return its file.
        DB::table('vendor_documents')
            ->where('type', 'insurance_coi')
            ->whereNull('deleted_at')
            ->orderBy('vendor_id')
            ->orderBy('expires_on')
            ->each(function ($document) {
                DB::table('vendors')->where('id', $document->vendor_id)->update([
                    'coi_expires_at' => $document->expires_on,
                    'insurer' => $document->issuer,
                    'policy_number' => $document->reference,
                    'coi_alert_stage' => $document->alert_stage,
                    'coi_alert_for' => $document->alert_for,
                ]);

                DB::table('media')
                    ->where('model_type', 'App\Models\VendorDocument')
                    ->where('model_id', $document->id)
                    ->update([
                        'model_type' => 'App\Models\Vendor',
                        'model_id' => $document->vendor_id,
                        'collection_name' => 'coi',
                    ]);
            });

        Schema::dropIfExists('vendor_documents');
    }
};
