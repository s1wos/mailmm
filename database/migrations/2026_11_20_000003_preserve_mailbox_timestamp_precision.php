<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changePrecision(6);
    }

    public function down(): void
    {
        $this->changePrecision(0);
    }

    private function changePrecision(int $precision): void
    {
        Schema::table('emails', function (Blueprint $table) use ($precision) {
            foreach (['created_at', 'updated_at', 'expires_at', 'closed_at'] as $column) {
                $table->timestamp($column, $precision)->nullable()->change();
            }
        });
    }
};
