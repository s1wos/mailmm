<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_issuances', function (Blueprint $table) {
            $table->dropUnique(['email_id']);
            $table->char('parameters_hash', 64)->nullable()->change();
        });
        DB::statement('CREATE UNIQUE INDEX mailbox_issuances_creation_email ON mailbox_issuances (email_id) WHERE parameters_hash IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX mailbox_issuances_reset_email ON mailbox_issuances (email_id) WHERE parameters_hash IS NULL');
    }

    public function assertCanRollBack(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE mailbox_issuances IN SHARE ROW EXCLUSIVE MODE');
        }
        if (DB::table('mailbox_issuances')->whereNull('parameters_hash')->where('expires_at', '>', now())->exists()) {
            throw new RuntimeException('Wait for outstanding password reset receipts to be read or expire before rollback (up to 10 minutes).');
        }
    }

    public function down(): void
    {
        DB::table('mailbox_issuances')->whereNull('parameters_hash')->delete();
        Schema::table('mailbox_issuances', function (Blueprint $table) {
            $table->dropIndex('mailbox_issuances_reset_email');
            $table->dropIndex('mailbox_issuances_creation_email');
            $table->char('parameters_hash', 64)->nullable(false)->change();
            $table->unique('email_id');
        });
    }
};
