<?php

namespace Tests\Feature;

use App\Models\MailboxDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailboxDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_only_the_configured_domain_and_preserves_its_mode_on_restart(): void
    {
        $this->assertDatabaseCount('mailbox_domains', 1);
        $seed = MailboxDomain::sole();
        $this->assertSame(strtolower(rtrim(trim(config('mailbox.domain')), '.')), $seed->name);
        $this->assertTrue($seed->issuance_enabled);

        $seed->update(['issuance_enabled' => false]);
        config(['mailbox.domain' => 'different.test']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($seed->id, MailboxDomain::sole()->id);
        $this->assertFalse($seed->fresh()->issuance_enabled);
    }

    public function test_canonical_domain_is_unique_and_new_domains_only_receive_mail(): void
    {
        $domain = MailboxDomain::create(['name' => ' Example.TEST. '])->refresh();
        $this->assertSame('example.test', $domain->name);
        $this->assertFalse($domain->issuance_enabled);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => MailboxDomain::create(['name' => 'example.test']));
    }

    public function test_252_byte_domain_and_ascii_punycode_are_accepted(): void
    {
        $name = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('z', 60);
        $this->assertSame($name, MailboxDomain::create(['name' => $name])->fresh()->name);
        $this->assertSame('xn--e1afmkfd.test', MailboxDomain::create(['name' => 'XN--E1AFMKFD.test'])->name);
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_invalid_domain_names(string $name): void
    {
        try {
            MailboxDomain::create(['name' => $name]);
            $this->fail('An invalid name was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
        $this->assertDatabaseCount('mailbox_domains', 1);
    }

    public static function invalidNames(): iterable
    {
        foreach (['localhost', 'two..labels.test', 'example.test..', 'a b.test', '*.example.test',
            '-first.test', 'last-.test', 'почта.test', str_repeat('a', 64).'.test',
            str_repeat(str_repeat('a', 63).'.', 3).str_repeat('z', 61)] as $name) {
            yield [$name];
        }
    }
}
