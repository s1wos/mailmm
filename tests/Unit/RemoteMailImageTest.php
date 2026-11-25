<?php

namespace Tests\Unit;

use App\Services\RemoteMailImage;
use League\Uri\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RemoteMailImageTest extends TestCase
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public static function invalidDestinations(): iterable
    {
        foreach ([
            'file:///image', 'ftp://cdn.example.com/a', '//cdn.example.com/a',
            'http://user:pass@cdn.example.com/', 'https://@cdn.example.com/', 'http://app/',
            'http://cdn.example.com:8080/', 'https://cdn.example.com:80/', 'http://cdn.example.com:080/',
            'https://cdn.example.com\\other/', "https://cdn.example.com/\n", 'http://%38.8.8.8/',
            'http://127.1/', 'http://0177.0.0.1/', 'http://0x08080808/', 'http://134744072/',
            'http://8.８.8.8/', 'http://cdn.local/', 'http://localhost/', 'http://x.home.arpa/',
            'http://127.0.0.1/', 'http://10.0.0.1/', 'http://172.16.0.1/', 'http://192.168.0.1/',
            'http://169.254.1.2/', 'http://168.63.129.16/', 'http://100.64.0.1/', 'http://192.0.2.1/',
            'http://198.18.0.1/', 'http://224.0.0.1/', 'http://192.88.99.1/', 'http://255.255.255.255/',
            'http://[::1]/', 'http://[fc00::1]/', 'http://[fe80::1%25eth0]/', 'http://[fec0::1]/',
            'http://[::ffff:808:808]/', 'http://[64:ff9b::808:808]/', 'http://[2002:808:808::1]/',
            'http://[2001::1]/', 'http://[2001:db8::1]/', 'http://[3fff::1]/', 'http://[ff02::1]/',
            'http://[2001:4860:1:2:0:5efe:808:808]/',
        ] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_destinations_open_no_connection(string $url): void
    {
        $service = $this->createPartialMock(RemoteMailImage::class, ['resolve', 'download']);
        $service->expects($this->never())->method('resolve');
        $service->expects($this->never())->method('download');
        self::assertNull($service->fetch($url));
    }

    public function test_every_redirect_resolves_again_and_a_private_answer_blocks_the_whole_hop(): void
    {
        $service = $this->createPartialMock(RemoteMailImage::class, ['resolve', 'download']);
        $service->expects($this->exactly(2))->method('resolve')->with('cdn.example.com', $this->lessThanOrEqual(1500))
            ->willReturnOnConsecutiveCalls(['8.8.8.8'], ['8.8.8.8', 'fd00::1']);
        $service->expects($this->once())->method('download')
            ->with($this->callback(fn (Http $uri) => (string) $uri === 'https://cdn.example.com/a'), '8.8.8.8')
            ->willReturn(['status' => 302, 'location' => '/b', 'bytes' => '']);
        self::assertNull($service->fetch('https://cdn.example.com/a#fragment'));
    }

    public function test_redirect_count_is_bounded_even_on_a_public_peer(): void
    {
        $service = $this->createPartialMock(RemoteMailImage::class, ['download']);
        $service->expects($this->exactly(4))->method('download')
            ->willReturn(['status' => 301, 'location' => 'next', 'bytes' => '']);
        self::assertNull($service->fetch('https://[2001:4860:4860::8888]/a'));
    }

    public function test_an_unreachable_public_address_falls_back_to_another_approved_address(): void
    {
        $bytes = base64_decode(self::GIF);
        $attempts = [];
        $service = $this->createPartialMock(RemoteMailImage::class, ['resolve', 'download']);
        $service->method('resolve')->willReturn(['8.8.8.8', '1.1.1.1']);
        $service->expects($this->exactly(2))->method('download')
            ->willReturnCallback(function (Http $uri, string $ip, int $timeout, int $budget) use (&$attempts, $bytes) {
                $attempts[] = [$ip, $timeout, $budget];

                return $ip === '8.8.8.8'
                    ? ['status' => 0, 'location' => '', 'bytes' => '']
                    : ['status' => 200, 'location' => '', 'bytes' => $bytes];
            });

        self::assertSame(['mime' => 'image/gif', 'bytes' => $bytes], $service->fetch('https://cdn.example.com/a'));
        self::assertSame(['8.8.8.8', '1.1.1.1'], array_column($attempts, 0));
        self::assertLessThanOrEqual($attempts[0][1], $attempts[1][1]);
        self::assertSame($attempts[0][2], $attempts[1][2]);
    }

    public function test_a_failed_response_does_not_start_another_download(): void
    {
        $service = $this->createPartialMock(RemoteMailImage::class, ['resolve', 'download']);
        $service->method('resolve')->willReturn(['8.8.8.8', '1.1.1.1']);
        $service->expects($this->once())->method('download')->willReturn(null);

        self::assertNull($service->fetch('https://cdn.example.com/a'));
    }

    public static function rasterResponses(): iterable
    {
        $gif = base64_decode(self::GIF);
        yield 'GIF' => [$gif, 'image/gif'];
        yield 'PNG' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+iFp8AAAAASUVORK5CYII='), 'image/png'];
        yield 'WebP' => [base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA'), 'image/webp'];
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', null];
        yield 'HTML' => ['<html><body>Image</body></html>', null];
        yield 'truncated' => [substr($gif, 0, 24), null];
        yield 'appended document' => [$gif.'<html></html>', null];
        yield 'oversized width' => [substr_replace($gif, pack('v', 8193), 6, 2), null];
        yield 'oversized area' => [substr_replace($gif, pack('vv', 4001, 4001), 6, 4), null];
    }

    #[DataProvider('rasterResponses')]
    public function test_response_type_comes_from_the_bounded_image_bytes(string $bytes, ?string $mime): void
    {
        $service = $this->createPartialMock(RemoteMailImage::class, ['download']);
        $service->expects($this->once())->method('download')->willReturn(['status' => 200, 'location' => '', 'bytes' => $bytes]);
        self::assertSame($mime ? ['mime' => $mime, 'bytes' => $bytes] : null, $service->fetch('http://8.8.8.8/img'));
    }

    public function test_actual_transport_pins_the_peer_strips_headers_and_bounds_chunked_downloads(): void
    {
        $server = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/mail-image-http.php']);
        $server->setTimeout(10)->start();
        try {
            $server->waitUntil(fn () => str_contains($server->getOutput(), "\n"));
            $address = trim($server->getOutput());
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
            $port = (int) substr(strrchr($address, ':'), 1);
            $service = new class($port) extends RemoteMailImage
            {
                public function __construct(private readonly int $port) {}

                protected function resolve(string $host, int $timeoutMs): array
                {
                    return ['8.8.8.8'];
                }

                protected function download(Http $uri, string $ip, int $timeoutMs, int $maxBytes): ?array
                {
                    // Route approved requests into the fixture. The production cURL transport still constructs each request.
                    return parent::download($uri->withPort($this->port), '127.0.0.1', $timeoutMs, $maxBytes);
                }
            };
            $bytes = base64_decode(self::GIF);
            self::assertSame(['mime' => 'image/gif', 'bytes' => $bytes], $service->fetch('http://cdn.example.com/redirect'));
            self::assertNull($service->fetch('http://cdn.example.com/large'));
            $server->wait();
            self::assertTrue($server->isSuccessful(), $server->getErrorOutput());
            $requests = json_decode(explode("\n", trim($server->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(3, $requests);
            self::assertStringContainsString('GET /img?key=fixture HTTP/', $requests[1]);
            self::assertStringContainsString('Host: assets.example.com:'.$port, $requests[1]);
            foreach ($requests as $request) {
                self::assertDoesNotMatchRegularExpression('/^(?:Cookie|Authorization|Proxy-Authorization|Referer|Forwarded|X-Forwarded-For|X-Real-IP):/im', $request);
            }
        } finally {
            $server->stop(0);
        }
    }
}
