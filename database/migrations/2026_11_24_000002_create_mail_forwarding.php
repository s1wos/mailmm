<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_forwarding_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->unique()->constrained('emails')->cascadeOnDelete();
            $table->string('destination', 254)->nullable();
            $table->string('status', 16)->default('disabled');
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('activated_at', 6)->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at', 6)->nullable();
            $table->timestamp('resend_at', 6)->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->string('disabled_reason', 64)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps(6);
        });
        Schema::create('mail_forwards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 8);
            $table->foreignId('initiator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->nullable();
            $table->unique(['initiator_id', 'request_id']);
            $table->foreignId('rule_id')->nullable()->constrained('mailbox_forwarding_rules')->nullOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('destination', 254);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('attempt_id')->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('submitted_at', 6)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps(6);
            $table->index(['message_id', 'created_at']);
            $table->index(['rule_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
        DB::statement("CREATE UNIQUE INDEX mail_forwards_auto_message ON mail_forwards (message_id) WHERE mode = 'auto'");
        Schema::create('forwarding_quota_buckets', function (Blueprint $table) {
            $table->unsignedBigInteger('hour');
            $table->string('scope', 100);
            $table->unsignedBigInteger('used')->default(0);
            $table->primary(['hour', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forwarding_quota_buckets');
        Schema::dropIfExists('mail_forwards');
        Schema::dropIfExists('mailbox_forwarding_rules');
    }
};
