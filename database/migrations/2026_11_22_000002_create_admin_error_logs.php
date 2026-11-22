<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->timestampTz('occurred_at', 6);
            $table->string('service', 32);
            $table->string('level', 16);
            $table->text('message');
            $table->index(['occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_error_logs');
    }
};
