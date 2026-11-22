<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailSchemaUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--realpath' => true, '--path' => array_merge(
            glob(database_path('migrations/0001_*.php')),
            glob(database_path('migrations/2025_*.php')),
        )];
    }

    public function test_recently_ingested_legacy_mail_survives_upgrade_and_pruning(): void
    {
        ParallelTesting::resolveTokenUsing(fn () => 'upgrade_'.getmypid());
        Storage::fake('local');
        $ingested = now()->startOfSecond()->subDay();
        $recipient = 'legacy@example.test';
        $box = DB::table('emails')->insertGetId(['email' => $recipient]);
        $message = DB::table('messages')->insertGetId([
            'email_id' => $box, 'from' => 'sender@example.test', 'subject' => 'Legacy letter',
            'received_at' => $ingested->copy()->subYears(5), 'created_at' => $ingested,
        ]);
        $bytes = "legacy\0file\xff";
        $path = "emails/{$message}/contracts/2026\\report.bin";
        Storage::disk('local')->put($path, $bytes);
        DB::table('messages')->where('id', $message)->update(['attachments' => json_encode([
            ['filename' => 'contracts/2026\\report.bin', 'path' => $path, 'size' => strlen($bytes), 'mime' => 'application/octet-stream'],
        ], JSON_THROW_ON_ERROR)]);

        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('mail:prune')->assertSuccessful();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = "/admin/messages/{$message}";
        $result = $this->getJson($url)->assertOk()->assertJsonPath('letter.recipient', $recipient)
            ->assertJsonPath('letter.subject', 'Legacy letter')->assertJsonPath('letter.attachments.0.url', $url.'/attachments/0');
        self::assertSame($ingested->timestamp, strtotime($result->json('letter.received_at')));
        $this->get($url.'/attachments/0')->assertDownload('report.bin')->assertStreamedContent($bytes);
        $this->get($url.'/attachments/0', ['Range' => 'bytes=1-3'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 1-3/'.strlen($bytes));
        $this->travelTo($ingested->copy()->addDays(365)->addSecond());
        $this->artisan('mail:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('messages', ['id' => $message]);
    }
}
