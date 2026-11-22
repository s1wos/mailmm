<?php

namespace App\Services;

use Closure;
use DateTimeImmutable;
use Generator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class ContainerErrorStreams
{
    private const GATEWAY = 'http://log-reader:8080';

    /** @return array<string, array{path: string, service: string, exited: bool}> */
    public function sources(): array
    {
        $containers = Http::timeout(5)->get(self::GATEWAY.'/containers')->throw()->json();
        if (! is_array($containers)) {
            throw new RuntimeException('Invalid Docker container list.');
        }

        $sources = [];
        foreach ($containers as $container) {
            $labels = $container['Labels'];
            $service = $labels['com.docker.compose.service'] ?? '';
            $replica = $labels['com.docker.compose.container-number'] ?? '';
            if (! in_array($service, LogCollectionHealth::SERVICES, true)
                || ! ctype_digit($replica) || strtolower($labels['com.docker.compose.oneoff'] ?? '') === 'true'
                || $container['State'] === 'created') {
                continue;
            }
            $project = $labels['com.docker.compose.project'];
            if (! in_array("/$project-$service-$replica", $container['Names'], true)) {
                continue;
            }
            $sources[$container['Id']] = [
                'path' => "$service/$replica",
                'service' => $service,
                'exited' => in_array($container['State'], ['exited', 'dead'], true),
            ];
        }

        return $sources;
    }

    public function probe(array $sources): array
    {
        $responses = Http::pool(function (Pool $pool) use ($sources) {
            foreach ($sources as $source) {
                $path = $source['path'];
                $pool->as($path)->timeout(4)->get(self::GATEWAY.'/logs/'.$path, ['tail' => 0]);
            }
        }, concurrency: 8);

        return array_map(fn ($result) => $result instanceof Response && $result->successful(), $responses);
    }

    /** @param array{path: string, service: string, exited: bool} $source */
    public function read(string $id, array $source, bool $follow, ?Closure $reportReady = null): bool
    {
        $key = 'admin-error-log-position:'.$id;
        $saved = (int) Cache::get($key, 0);
        $newest = $saved;
        $query = ['since' => $saved] + ($follow ? ['follow' => 1] : ['until' => time()]);
        $response = Http::timeout($follow ? 0 : 30)->withOptions(['stream' => true, 'read_timeout' => $follow ? 0 : 30])
            ->get(self::GATEWAY.'/logs/'.$source['path'], $query);
        if ($response->notFound()) {
            return false;
        }

        $body = $response->throw()->toPsrResponse()->getBody();
        try {
            $reportReady?->__invoke(true);
            foreach ($this->frames($body) as [$channel, $line]) {
                $separator = strpos($line, ' ');
                if ($separator === false) {
                    continue;
                }
                $date = new DateTimeImmutable(substr($line, 0, $separator));
                $error = ContainerErrorParser::parse($source['service'], substr($line, $separator + 1));
                $newest = max($newest, $date->getTimestamp());
                $checkpointDue = $newest - $saved >= 15;
                if ($error === null && ! $checkpointDue) {
                    continue;
                }
                $reportReady?->__invoke(false);
                if ($error !== null) {
                    DB::table('admin_error_logs')->insertOrIgnore($error + [
                        'event_key' => hash('sha256', $source['path']."\0".$channel.$line),
                        'occurred_at' => $date->format('Y-m-d H:i:s.uP'),
                        'service' => $source['service'],
                    ]);
                }
                if ($checkpointDue) {
                    Cache::put($key, $newest, now()->addDays(30));
                    $saved = $newest;
                }
                $reportReady?->__invoke(true);
            }
            if ($newest > $saved) {
                $reportReady?->__invoke(false);
                Cache::put($key, $newest, now()->addDays(30));
                $reportReady?->__invoke(true);
            }
        } finally {
            $body->close();
        }
        return true;
    }

    /** @return Generator<int, array{string, string}> */
    private function frames(StreamInterface $body): Generator
    {
        while (($header = Utils::copyToString($body, 8)) !== '') {
            if (strlen($header) !== 8 || ! in_array(ord($header[0]), [1, 2], true)) {
                throw new RuntimeException('Invalid Docker stream header.');
            }
            $length = unpack('Nlength', $header, 4)['length'];
            if ($length > 16 * 1024 * 1024) {
                throw new RuntimeException('Oversized Docker stream frame.');
            }
            $line = Utils::copyToString($body, $length);
            if (strlen($line) !== $length) {
                throw new RuntimeException('Incomplete Docker stream frame.');
            }
            yield [$header[0], $line];
        }
    }
}
