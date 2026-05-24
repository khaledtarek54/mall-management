<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('noteable'); // polymorphic — Tenant, Lease, Invoice
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->enum('channel', ['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'other'])->default('other');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();

            $table->index(['noteable_type', 'noteable_id', 'contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
