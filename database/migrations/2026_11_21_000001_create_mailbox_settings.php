<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('guest_issuance_enabled')->default(true);
        });
        DB::table('mailbox_settings')->insert(['id' => 1, 'guest_issuance_enabled' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_settings');
    }
};
