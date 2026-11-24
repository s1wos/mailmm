<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('raw_path')->nullable();
            $table->unsignedBigInteger('raw_size')->nullable();
            $table->char('raw_sha256', 64)->nullable();
            $table->json('forwarding_context')->nullable();
        });
    }

    public function assertCanRollBack(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE messages IN SHARE ROW EXCLUSIVE MODE');
        }
        if (DB::table('messages')->whereNotNull('raw_path')->exists()) {
            throw new RuntimeException('Restore from backup or retain the schema and cleanup for stored originals.');
        }
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn([
            'raw_path', 'raw_size', 'raw_sha256', 'forwarding_context',
        ]));
    }
};
