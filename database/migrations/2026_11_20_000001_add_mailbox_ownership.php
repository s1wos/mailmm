<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
        });

        DB::table('emails')->update(['expires_at' => now(), 'closed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropUnique(['owner_user_id']);
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['closed_at']);
            $table->dropColumn(['owner_user_id', 'expires_at', 'closed_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
