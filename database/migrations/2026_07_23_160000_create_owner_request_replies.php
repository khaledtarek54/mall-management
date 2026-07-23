<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner requests are a COMMUNICATION channel, but had no conversation (module 15).
 *
 * The whole thing was one `subject`/`body` from the owner plus a single `resolution_notes` field the
 * operator overwrote — and which was silently DROPPED unless the status was set to `resolved`. So an
 * operator replying "we're looking into it" while moving it to in-progress lost their message, and
 * there was never a back-and-forth. This is the thread: every reply is a first-class, attributed,
 * timestamped message, immutable once posted (a conversation log, not editable state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_request_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_request_id')->constrained()->cascadeOnDelete();
            // Who wrote it — nullable so a deleted staff/owner account doesn't erase the history.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('owner_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_request_replies');
    }
};
