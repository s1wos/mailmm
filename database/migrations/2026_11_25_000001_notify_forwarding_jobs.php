<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection();
        if ($database->getDriverName() !== 'pgsql') {
            return;
        }
        $table = $database->getQueryGrammar()->wrapTable(config('queue.connections.forwarding.table'));
        $database->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION mailmm_notify_forwarding_job() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM pg_notify('mailmm_forwarding_jobs', '');
                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER mailmm_notify_forwarding_job
            AFTER INSERT ON {$table}
            FOR EACH ROW WHEN (NEW.queue = 'forwarding')
            EXECUTE FUNCTION mailmm_notify_forwarding_job();
            SQL);
    }

    public function down(): void
    {
        $database = DB::connection();
        if ($database->getDriverName() !== 'pgsql') {
            return;
        }
        $table = $database->getQueryGrammar()->wrapTable(config('queue.connections.forwarding.table'));
        $database->unprepared("DROP TRIGGER IF EXISTS mailmm_notify_forwarding_job ON {$table}; DROP FUNCTION IF EXISTS mailmm_notify_forwarding_job();");
    }
};
