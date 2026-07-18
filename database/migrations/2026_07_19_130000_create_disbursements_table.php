<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner disbursements (module 27) — a payout against a finalised owner statement. Paying an
 * owner clears the Due-to-Owner liability the statement accrued: Dr Due to Owner / Cr Bank.
 * Partial payouts are allowed; the running total can never exceed the owner's share (enforced
 * in the service under lock). `required_permission` is frozen at schedule from the approval
 * ladder so a later amount edit can't launder the authority that signed it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();               // DISB-YYYY-NNNN
            $table->foreignId('owner_statement_id')->constrained('owner_statements')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets'); // denormalized → journalizer reads its own row
            $table->foreignId('user_id')->constrained('users');   // the payee (owner)

            $table->decimal('amount', 14, 2)->default(0);
            $table->string('method')->default('bank_transfer');   // bank_transfer | cheque | cash
            $table->string('required_permission')->nullable();    // frozen at schedule (null = no approval configured)
            $table->string('status')->default('scheduled');       // scheduled | approved | paid | cancelled
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('paid_on')->nullable();                  // GL entry_date once paid
            $table->string('external_reference')->nullable();     // cheque no / transfer ref

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'paid_on']);
            $table->index('owner_statement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
