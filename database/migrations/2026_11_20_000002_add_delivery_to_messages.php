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
            $table->dropForeign(['email_id']);
            $table->foreignId('email_id')->nullable()->change();
            $table->foreign('email_id')->references('id')->on('emails')->nullOnDelete();
            $table->text('subject')->nullable()->change();
            $table->text('from')->change();
            $table->string('recipient')->nullable();
            $table->char('delivery_key', 64)->nullable()->unique();
            $table->boolean('is_pending')->default(false);
            $table->index('received_at');
            $table->index(['recipient', 'received_at']);
        });

        DB::table('messages')->update([
            'recipient' => DB::raw('(SELECT emails.email FROM emails WHERE emails.id = messages.email_id)'),
            'received_at' => DB::raw('COALESCE(created_at, received_at)'),
        ]);

        Schema::table('messages', fn (Blueprint $table) => $table->string('recipient')->nullable(false)->change());
    }

    public function assertCanRollBack(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE messages IN SHARE ROW EXCLUSIVE MODE');
        }
        if (DB::table('messages')->whereNull('email_id')->orWhere('is_pending', true)
            ->orWhereRaw('length("from") > 255')->orWhereRaw('length(subject) > 255')->exists()) {
            throw new RuntimeException('The previous schema cannot store archived or pending mail, or senders or subjects over 255 characters. Restore from backup to downgrade.');
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['email_id']);
            $table->foreignId('email_id')->nullable(false)->change();
            $table->foreign('email_id')->references('id')->on('emails')->cascadeOnDelete();
            $table->string('subject')->nullable()->change();
            $table->string('from')->change();
            $table->dropIndex(['recipient', 'received_at']);
            $table->dropIndex(['received_at']);
            $table->dropUnique(['delivery_key']);
            $table->dropColumn(['recipient', 'delivery_key', 'is_pending']);
        });
    }
};
