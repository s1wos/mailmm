<?php

namespace Tests\Feature;

use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MailSchemaRollbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--realpath' => true, '--path' => array_merge(
            glob(database_path('migrations/0001_*.php')),
            glob(database_path('migrations/2025_*.php')),
        )])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public static function incompatibleRollbacks(): array
    {
        return [
            'saved MIME original' => ['migrate:rollback', [], ['raw_path' => 'mail/ab/archived.eml']],
            'archived mail in the latest batch' => ['migrate:rollback', [], ['email_id' => null]],
            'pending mail in an explicit batch' => ['migrate:rollback', ['--batch' => 2], ['is_pending' => true]],
            'reset across both batches' => ['migrate:reset', [], ['email_id' => null]],
            'subject exceeds the legacy column' => ['migrate:rollback', [], ['subject' => str_repeat('я', 256)]],
            'sender exceeds the legacy column' => ['migrate:rollback', [], ['from' => str_repeat('я', 256)]],
        ];
    }

    #[DataProvider('incompatibleRollbacks')]
    public function test_incompatible_mail_blocks_every_selected_migration(string $command, array $options, array $mail): void
    {
        $history = DB::table('migrations')->orderBy('id')->get()->all();
        $key = str_repeat('a', 64);
        $box = DB::table('emails')->insertGetId(['email' => 'inbox@example.test', 'guest_session_key' => $key]);
        $letter = DB::table('messages')->insertGetId($mail + [
            'email_id' => $box, 'recipient' => 'inbox@example.test',
            'from' => 'sender@example.test', 'subject' => 'Preserve this letter', 'received_at' => now(),
        ]);
        DB::table('mailbox_domains')->insert(['name' => 'custom.example', 'issuance_enabled' => true]);
        DB::table('mailbox_settings')->where('id', 1)->update(['guest_issuance_enabled' => false]);
        DB::table('mailbox_issuances')->insert([
            'request_key' => $key, 'parameters_hash' => $key, 'email_id' => $box, 'session_hash' => $key,
            'encrypted_password' => 'receipt-content',
        ]);

        $started = [];
        Event::listen(MigrationStarted::class, function ($event) use (&$started) {
            $started[] = $event->name;
        });
        $failure = null;
        try {
            Artisan::call($command, $options);
        } catch (RuntimeException $e) {
            $failure = $e->getMessage();
        }

        self::assertStringContainsString('Restore from backup', $failure ?? '');
        self::assertEquals($history, DB::table('migrations')->orderBy('id')->get()->all());
        self::assertSame([], $started, 'Compatibility must be checked before the first down().');
        $this->assertDatabaseHas('messages', ['id' => $letter] + $mail);
        $this->assertDatabaseHas('mailbox_domains', ['name' => 'custom.example', 'issuance_enabled' => true]);
        $this->assertDatabaseHas('mailbox_settings', ['guest_issuance_enabled' => false]);
        $this->assertDatabaseHas('emails', ['id' => $box, 'guest_session_key' => $key]);
        $this->assertDatabaseHas('mailbox_issuances', ['request_key' => $key, 'encrypted_password' => 'receipt-content']);
    }

    public function test_compatible_mail_survives_rolling_back_the_upgrade_and_migrating_again(): void
    {
        $box = DB::table('emails')->insertGetId(['email' => 'inbox@example.test']);
        $letter = DB::table('messages')->insertGetId([
            'email_id' => $box, 'recipient' => 'inbox@example.test', 'from' => 'sender@example.test', 'received_at' => now(),
        ]);

        $this->artisan('migrate:rollback')->assertSuccessful();
        self::assertFalse(Schema::hasColumn('messages', 'recipient'));
        $this->assertDatabaseHas('messages', ['id' => $letter, 'email_id' => $box]);
        $this->artisan('migrate')->assertSuccessful();
        $this->assertDatabaseHas('messages', ['id' => $letter, 'recipient' => 'inbox@example.test']);
    }

    public function test_failure_during_a_later_down_restores_the_first_table_and_its_history(): void
    {
        DB::table('admin_error_logs')->insert([
            'event_key' => 'preserve', 'occurred_at' => now(), 'service' => 'app', 'level' => 'error', 'message' => 'Preserve',
        ]);
        $history = DB::table('migrations')->orderBy('id')->get()->all();
        Event::listen(MigrationStarted::class, function ($event) {
            if ($event->name === '2026_11_22_000001_create_mailbox_issuances') {
                throw new RuntimeException('Interrupted second migration');
            }
        });

        $failure = null;
        try {
            Artisan::call('migrate:rollback', ['--path' => [
                'database/migrations/2026_11_22_000002_create_admin_error_logs.php',
                'database/migrations/2026_11_22_000001_create_mailbox_issuances.php',
            ]]);
        } catch (RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertSame('Interrupted second migration', $failure);
        self::assertEquals($history, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertDatabaseHas('admin_error_logs', ['event_key' => 'preserve']);
    }

    public function test_archive_allows_rolling_back_only_unrelated_migrations(): void
    {
        DB::table('messages')->insert([
            'email_id' => null, 'recipient' => 'archive@example.test', 'from' => 'sender@example.test', 'received_at' => now(),
        ]);
        $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        $this->artisan('migrate:rollback', ['--step' => 1, '--pretend' => true])->assertSuccessful();
        $this->assertDatabaseHas('migrations', ['migration' => $latest]);
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('migrations', ['migration' => $latest]);
        $this->artisan('migrate:rollback', ['--path' => ['database/migrations/2026_11_22_000001_create_mailbox_issuances.php']])
            ->assertSuccessful();
        self::assertFalse(Schema::hasTable('mailbox_issuances'));
        $this->assertDatabaseHas('messages', ['recipient' => 'archive@example.test']);
    }

    public function test_receipt_upgrade_and_rollback_preserve_creation_keys_and_live_passwords(): void
    {
        $path = ['--path' => ['database/migrations/2026_11_23_000001_allow_password_reset_receipts.php']];
        $this->artisan('migrate:rollback', $path)->assertSuccessful();
        $id = DB::table('emails')->insertGetId(['email' => 'upgrade@example.test']);
        $creation = [
            'request_key' => str_repeat('c', 64), 'parameters_hash' => str_repeat('p', 64),
            'email_id' => $id, 'session_hash' => str_repeat('s', 64),
            'encrypted_password' => 'old-secret', 'expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ];
        DB::table('mailbox_issuances')->insert($creation);
        $this->artisan('migrate', $path)->assertSuccessful();
        $this->assertDatabaseHas('mailbox_issuances', $creation);

        $reset = [...$creation, 'request_key' => str_repeat('r', 64), 'parameters_hash' => null, 'encrypted_password' => 'new-secret'];
        DB::table('mailbox_issuances')->insert($reset);
        try {
            Artisan::call('migrate:rollback', $path);
            self::fail('A live password reset receipt must survive a rollback attempt.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outstanding password reset receipts', $error->getMessage());
        }
        $this->assertDatabaseHas('mailbox_issuances', $reset);
        $this->travel(11)->minutes();
        $this->artisan('migrate:rollback', $path)->assertSuccessful();
        $this->assertDatabaseMissing('mailbox_issuances', ['request_key' => $reset['request_key']]);
        $this->assertDatabaseHas('mailbox_issuances', $creation);
        self::assertTrue(Schema::hasIndex('mailbox_issuances', ['email_id'], 'unique'));
    }
}
