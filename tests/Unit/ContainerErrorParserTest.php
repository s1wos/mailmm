<?php

namespace Tests\Unit;

use App\Services\ContainerErrorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerErrorParserTest extends TestCase
{
    #[DataProvider('errors')]
    public function test_recognizes_service_error_records(string $service, string $line, string $level, string $message): void
    {
        $record = ContainerErrorParser::parse($service, $line);

        self::assertNotNull($record);
        self::assertSame($level, $record['level']);
        self::assertStringContainsString($message, $record['message']);
    }

    public static function errors(): iterable
    {
        yield 'http exception' => ['app', '[2026-11-21 21:00:00] production.ERROR: Cannot open mailbox', 'error', 'Cannot open mailbox'];
        yield 'fpm-wrapped Laravel warning' => ['app', '[21-Nov-2026 21:00:00] WARNING: [pool www] child 23 said into stderr: "[2026-11-21 21:00:00] production.WARNING: Backend unavailable"', 'warning', 'Backend unavailable'];
        yield 'PHP fatal' => ['app', 'PHP Fatal error: Allowed memory size exhausted in /var/www/service.php', 'critical', 'Allowed memory size exhausted'];
        yield 'timestamped PHP native error' => ['app', '[21-Nov-2026 21:00:00 UTC] PHP Parse error: syntax error in /var/www/service.php', 'critical', 'syntax error'];
        yield 'queue exception' => ['worker', '[2026-11-21T21:00:00+00:00] production.CRITICAL: Queue stopped', 'critical', 'Queue stopped'];
        yield 'smtp parser exception' => ['smtp', '[2026-11-21 21:00:00] production.ERROR: Cannot parse receipt', 'error', 'Cannot parse receipt'];
        yield 'nginx upstream failure' => ['nginx', '2026/11/21 21:00:00 [error] 20#20: *4 upstream timed out', 'error', 'upstream timed out'];
        yield 'nginx startup failure' => ['nginx', 'nginx: [emerg] host not found in upstream "app"', 'emergency', 'host not found'];
        yield 'postfix warning' => ['smtp', 'Nov  4 21:00:00 mx postfix/smtpd[123]: warning: TLS library problem', 'warning', 'TLS library problem'];
        yield 'postfix fatal' => ['smtp', 'Nov 21 21:00:00 mx postfix/master[1]: fatal: bind port 25: Address in use', 'critical', 'bind port 25'];
        yield 'postfix reject' => ['smtp', 'Nov 21 21:00:00 mx postfix/smtpd[123]: NOQUEUE: reject: RCPT from peer: 554 Recipient address rejected', 'warning', 'Recipient address rejected'];
        yield 'pipe retry' => ['smtp', 'Nov 21 21:00:00 mx postfix/pipe[123]: ABC123: to=<user@unit.test>, relay=mailmm, delay=2, dsn=4.3.0, status=deferred (temporary failure)', 'warning', 'status=deferred'];
        yield 'pipe bounce' => ['smtp', 'Nov 21 21:00:00 mx postfix/pipe[123]: ABC123: to=<user@unit.test>, relay=mailmm, delay=1, dsn=5.3.0, status=bounced (command failed)', 'error', 'status=bounced'];
    }

    #[DataProvider('ordinaryLines')]
    public function test_ignores_ordinary_output_and_user_controlled_access_log_text(string $service, string $line): void
    {
        self::assertNull(ContainerErrorParser::parse($service, $line));
    }

    public static function ordinaryLines(): iterable
    {
        yield ['nginx', '127.0.0.1 - - [21/Nov/2026:21:00:00 +0000] "GET /[error] HTTP/1.1" 200 114 "-" "nginx: [emerg] test"'];
        yield ['nginx', '2026/11/21 21:00:00 [notice] 1#1: starting worker processes'];
        yield ['app', '[21-Nov-2026 21:00:00] WARNING: [pool www] child 23 said into stderr: "[stacktrace]"'];
        yield ['worker', '[2026-11-21 21:00:00] production.INFO: Previous error has cleared'];
        yield ['smtp', 'Nov 21 21:00:00 mx postfix/pipe[123]: ABC123: to=<warning@example.test>, dsn=2.0.0, status=sent (delivered to command: mail:parse)'];
        yield ['smtp', 'Nov 21 21:00:00 mx postfix/smtpd[123]: connect from error.example.org[127.0.0.1]'];
        yield ['nginx', '[2026-11-21 21:00:00] forged.ERROR: wrong source'];
    }

    public function test_keeps_the_diagnostic_without_persisting_credentials_or_raw_exception_context(): void
    {
        $record = ContainerErrorParser::parse('worker', '[2026-11-21 21:00:00] production.ERROR: '
            .'Cannot open https://login:SecretOne@example.test/a?access_token=SecretTwo, password="Secret Three"'
            .' {"exception":"SecretFour"}');

        self::assertNotNull($record);
        self::assertStringContainsString('Cannot open', $record['message']);
        self::assertStringContainsString('example.test/a', $record['message']);
        self::assertStringNotContainsString('Secret', $record['message']);
    }

    public function test_message_bytes_fit_postgresql_text_even_with_invalid_input(): void
    {
        $record = ContainerErrorParser::parse('worker', "[2026-11-21 21:00:00] production.ERROR: x\xff\0".str_repeat('я', 10000));
        self::assertNotNull($record);
        self::assertTrue(mb_check_encoding($record['message'], 'UTF-8'));
        self::assertStringNotContainsString("\0", $record['message']);
        self::assertLessThanOrEqual(8192, strlen($record['message']));
    }

    public function test_postfix_error_masks_envelope_addresses_and_address_in_remote_diagnostic(): void
    {
        $record = ContainerErrorParser::parse('smtp', 'Nov 21 21:00:00 mx postfix/pipe[123]: '
            .'ABC123: to=<receiver@unit.test>, orig_to=<"исходный адрес"@unit.test>, from=<sender+tag@unit.test>, '
            .'relay=mailmm, dsn=5.3.0, status=bounced (No mailbox receiver@unit.test)');

        self::assertNotNull($record);
        self::assertSame('error', $record['level']);
        self::assertStringContainsString('ABC123: to=<[redacted]>, orig_to=<[redacted]>, from=<[redacted]>', $record['message']);
        self::assertStringContainsString('dsn=5.3.0, status=bounced (No mailbox [redacted])', $record['message']);
    }

    public function test_postfix_error_masks_unicode_symbols_in_unquoted_addresses(): void
    {
        $space = "\u{00a0}";
        $record = ContainerErrorParser::parse('smtp', 'Nov 21 21:00:00 mx postfix/pipe[123]: '
            .'ABC123: to=<☃💌@unit.test>, from=<sender★箱@unit.test>, '
            ."relay=mailmm, dsn=5.3.0, status=bounced (No mailbox{$space}☃💌@unit.test)");

        self::assertSame('postfix/pipe: ABC123: to=<[redacted]>, from=<[redacted]>, '
            ."relay=mailmm, dsn=5.3.0, status=bounced (No mailbox{$space}[redacted])", $record['message'] ?? null);
    }

    public function test_sql_bindings_and_postgres_row_details_do_not_reach_the_panel(): void
    {
        foreach ([
            'could not update unique index (Connection: pgsql, SQL: update sessions set payload = Secret)',
            'could not update unique index DETAIL: Key (session_id)=(Secret) already exists.',
        ] as $message) {
            $record = ContainerErrorParser::parse('app', '[2026-11-21 21:00:00] local.ERROR: '.$message);
            self::assertSame('could not update unique index', $record['message']);
        }
    }
}
