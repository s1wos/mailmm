<?php

namespace App\Services;

use League\Uri\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class RemoteMailImage
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    /** @return array{mime: string, bytes: string}|null */
    public function fetch(string $url): ?array
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $remainingBytes = self::MAX_BYTES;
        $base = null;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $uri = $this->webUri($url, $base);
            if ($uri === null || ($timeout = $this->remainingMs($deadline)) <= 0) {
                return null;
            }
            $host = trim($uri->getHost(), '[]');
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host, min($timeout, 1500));
            if ($addresses === [] || ! array_all($addresses, $this->isPublicIp(...))) {
                return null;
            }
            $response = null;
            foreach ($addresses as $address) {
                $timeout = $this->remainingMs($deadline);
                if ($timeout <= 0 || $remainingBytes <= 0) {
                    return null;
                }
                $response = $this->download($uri, $address, $timeout, $remainingBytes);
                if ($response === null || $response['status'] !== 0) {
                    break;
                }
            }
            if ($response === null) {
                return null;
            }
            if ($response['status'] === 200) {
                return $this->raster($response['bytes']);
            }
            if (! in_array($response['status'], [301, 302, 303, 307, 308], true) || $response['location'] === '') {
                return null;
            }
            $remainingBytes -= strlen($response['bytes']);
            $base = $uri;
            $url = $response['location'];
        }

        return null;
    }

    private function remainingMs(int $deadline): int
    {
        return intdiv($deadline - hrtime(true), 1_000_000);
    }

    private function webUri(string $url, ?Http $base): ?Http
    {
        if ($url === '' || strlen($url) > 8192 || preg_match('~[\x00-\x20\x7f\\\\]~', $url)) {
            return null;
        }
        // Check the raw authority before URI normalization can erase empty userinfo or decode an encoded host.
        if (preg_match('~\A(?:[a-z][\w+.-]*:)?//([^/?#]*)~i', $url, $raw)
            && ! preg_match('~\A(?:\[[a-f\d:]+\]|[\p{L}\p{M}\p{N}.-]+)(?::(?:80|443))?\z~iu', $raw[1])) {
            return null;
        }
        $uri = Http::parse($url, $base);
        if ($uri === null || ! in_array($uri->getScheme(), ['http', 'https'], true)
            || $uri->getUserInfo() !== '' || $uri->getPort() !== null) {
            return null;
        }
        $host = $uri->getHost();
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $port = $uri->getScheme() === 'https' ? 443 : 80;
            if (isset($raw[1]) && ! in_array(strtolower($raw[1]), [$host, $host.':'.$port], true)) {
                return null;
            }
        } elseif (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! preg_match('~\.(?:[a-z]{2,63}|xn--[a-z\d-]+)\z~i', $host)
            || preg_match('~\.(?:localhost|local|internal|arpa|test|invalid|example|onion)\z~i', $host)) {
            return null;
        }

        return $uri->withFragment('');
    }

    private function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || IpUtils::isPrivateIp($ip)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            // Only global unicast. Exclude special assignments, documentation and ISATAP interface IDs.
            return IpUtils::checkIp($ip, '2000::/3') && ! IpUtils::checkIp($ip, ['2001::/23', '3fff::/20'])
                && ! in_array(substr(inet_pton($ip), 8, 4), ["\x00\x00\x5e\xfe", "\x02\x00\x5e\xfe"], true);
        }

        return ! IpUtils::checkIp($ip, ['224.0.0.0/4', '192.0.0.0/24', '192.88.99.0/24', '168.63.129.16']);
    }

    /** @return list<string> */
    protected function resolve(string $host, int $timeoutMs): array
    {
        // PHP's system DNS call has no per-call timeout. Isolate it so the request deadline can cancel it.
        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false) {
            return [];
        }
        $lookup = <<<'PHP'
            foreach (dns_get_record($argv[1], DNS_A | DNS_AAAA) ?: [] as $record) {
                echo ($record['ip'] ?? $record['ipv6'] ?? ''), "\n";
            }
            PHP;
        $process = new Process([$php, '-r', $lookup, '--', $host.'.'], timeout: $timeoutMs / 1000);
        try {
            $process->mustRun();
        } catch (RuntimeException) {
            return [];
        }

        return preg_split('/\s+/', trim($process->getOutput()), flags: PREG_SPLIT_NO_EMPTY);
    }

    /** @return array{status: int, location: string, bytes: string}|null */
    protected function download(Http $uri, string $ip, int $timeoutMs, int $maxBytes): ?array
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $bytes = '';
        $location = '';
        $headerBytes = 0;
        $curl = curl_init((string) $uri);
        $configured = curl_setopt_array($curl, [
            // Keep the original HTTP Host and TLS SNI/certificate name, but connect only to the approved numeric peer.
            CURLOPT_CONNECT_TO => ['::'.$address.':'],
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => ['Accept: image/png, image/jpeg, image/gif, image/webp'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location, &$headerBytes): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > 32 * 1024) {
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $location = '';
                } elseif (strncasecmp($line, 'Location:', 9) === 0) {
                    $location = trim(substr($line, 9));
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bytes, $maxBytes): int {
                $length = strlen($chunk);
                if (strlen($bytes) + $length > $maxBytes) {
                    return 0;
                }
                $bytes .= $chunk;

                return $length;
            },
        ]);
        if (! $configured) {
            return null;
        }
        if (curl_exec($curl) === false) {
            // Only try another peer when the failed attempt consumed no response data.
            return $headerBytes === 0 && $bytes === '' ? ['status' => 0, 'location' => '', 'bytes' => ''] : null;
        }

        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'location' => $location, 'bytes' => $bytes];
    }

    private function raster(string $bytes): ?array
    {
        $image = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (! $image || min($image[0], $image[1]) < 1 || max($image[0], $image[1]) > 8192
            || $image[0] * $image[1] > 16_000_000) {
            return null;
        }
        $framed = match ($image[2]) {
            IMAGETYPE_PNG => str_starts_with($bytes, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR")
                && str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82"),
            IMAGETYPE_GIF => in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)
                && strlen($bytes) >= 26 && str_ends_with($bytes, "\x3b"),
            IMAGETYPE_JPEG => str_starts_with($bytes, "\xff\xd8\xff") && str_ends_with($bytes, "\xff\xd9"),
            IMAGETYPE_WEBP => substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP'
                && unpack('V', substr($bytes, 4, 4))[1] === strlen($bytes) - 8,
            default => false,
        };

        return $framed ? ['mime' => $image['mime'], 'bytes' => $bytes] : null;
    }
}
