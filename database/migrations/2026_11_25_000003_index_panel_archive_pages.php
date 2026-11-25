<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['received_at', 'id']);
            $table->index(['recipient', 'received_at', 'id']);
            $table->dropIndex(['received_at']);
            $table->dropIndex(['recipient', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('received_at');
            $table->index(['recipient', 'received_at']);
            $table->dropIndex(['received_at', 'id']);
            $table->dropIndex(['recipient', 'received_at', 'id']);
        });
    }
};
