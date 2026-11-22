<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_issuances', function (Blueprint $table) {
            $table->char('request_key', 64)->primary();
            $table->char('parameters_hash', 64);
            $table->foreignId('email_id')->unique()->constrained('emails')->cascadeOnDelete();
            $table->char('session_hash', 64);
            $table->text('encrypted_password')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_issuances');
    }
};
