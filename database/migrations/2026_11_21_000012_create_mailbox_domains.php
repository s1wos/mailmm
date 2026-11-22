<?php

use App\Models\MailboxDomain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name', 252)->unique();
            $table->boolean('issuance_enabled')->default(false);
        });
        MailboxDomain::create(['name' => config('mailbox.domain'), 'issuance_enabled' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_domains');
    }
};
