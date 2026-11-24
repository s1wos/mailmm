<?php

namespace Tests\Unit;

use App\Exceptions\ForwardingFailure;
use App\Services\ForwardingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ForwardingSmtpTest extends TestCase
{
    public static function scenarios(): array
    {
        return [['accept', null], ['disconnect', 'submission_unknown'], ['recipient', 'recipient_rejected'],
            ['recipient-policy', 'smtp_rejected'], ['recipient-content', 'smtp_rejected'],
            ['recipient-ambiguous', 'smtp_rejected'], ['recipient-unknown', 'smtp_rejected'],
            ['data-policy', 'smtp_rejected'], ['data-recipient', 'recipient_rejected'],
            ['defer', 'smtp_temporary'], ['ascii', 'smtp_unsupported'], ['accept', null, '"платёж@дом"@outside.test'],
            ['ascii', 'smtp_unsupported', 'платёж@outside.test']];
    }

    #[DataProvider('scenarios')]
    public function test_local_smtp_captures_exact_bytes_one_recipient_and_final_reply(string $mode, ?string $reason, string $destination = 'target@example.net'): void
    {
        $server = new Process([PHP_BINARY, base_path('tests/fixtures/forwarding-smtp.php'), $mode]);
        $server->setTimeout(15)->start();
        try {
            $server->waitUntil(fn () => str_contains($server->getOutput(), "\n"));
            $address = trim($server->getOutput());
            $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
            config(['forwarding.auto_dsn' => 'smtp://'.$address, 'forwarding.timeout' => 2]);
            $wire = "From: Банк <shop@example.org>\r\nTo: private@old.test\r\nSubject: Invoice\r\n\r\n.\r\n..два\r\n\r\n";
            try {
                (new ForwardingTransport)->send('auto', new RawMessage($wire), $destination, 'token@bounce.test');
                $this->assertNull($reason);
            } catch (ForwardingFailure $failure) {
                $this->assertSame($reason, $failure->getMessage());
            }
            $server->wait();
            $this->assertTrue($server->isSuccessful(), $server->getErrorOutput());
            $capture = json_decode(explode("\n", trim($server->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            if ($mode === 'ascii') {
                $this->assertCount(0, array_filter($capture['commands'], fn ($line) => str_starts_with($line, 'MAIL FROM')));
            } else {
                $this->assertMatchesRegularExpression('/^MAIL FROM:<token@bounce.test> (?=.*BODY=8BITMIME)(?=.*SMTPUTF8)/',
                    array_values(array_filter($capture['commands'], fn ($line) => str_starts_with($line, 'MAIL FROM:')))[0]);
                $this->assertSame(['RCPT TO:<'.$destination.'>'], array_values(array_filter($capture['commands'], fn ($line) => str_starts_with($line, 'RCPT'))));
                if (! str_starts_with($mode, 'recipient')) {
                    $this->assertSame($wire, base64_decode($capture['body']));
                }
            }
        } finally {
            $server->stop();
        }
    }
}
