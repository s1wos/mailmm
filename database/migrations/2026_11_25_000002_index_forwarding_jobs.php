<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection();
        $table = $database->getQueryGrammar()->wrapTable(config('queue.connections.forwarding.table'));
        $postgres = $database->getDriverName() === 'pgsql';
        $name = $postgres
            ? "case when payload is json then payload::json->>'displayName' end"
            : "case when json_valid(payload) then json_extract(payload, '$.displayName') end";
        $database->statement("CREATE INDEX jobs_forwarding_name_index ON {$table} (({$name})) WHERE queue = 'forwarding'");

        if ($postgres) {
            $database->statement("CREATE INDEX jobs_unreserved_available_index ON {$table} (queue, available_at) WHERE reserved_at IS NULL");
            $database->statement("CREATE INDEX jobs_reserved_at_index ON {$table} (queue, reserved_at) WHERE reserved_at IS NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS jobs_forwarding_name_index');
        DB::statement('DROP INDEX IF EXISTS jobs_unreserved_available_index');
        DB::statement('DROP INDEX IF EXISTS jobs_reserved_at_index');
    }
};
