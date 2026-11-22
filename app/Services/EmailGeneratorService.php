<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailGeneratorService
{
    private const RANDOM_LENGTH = 14;

    private const MAX_CANDIDATES = 5;

    public function localPartLimit(string $domain): int
    {
        return max(0, min(64, 253 - strlen($domain)));
    }

    public function formatsFor(string $domain): array
    {
        $limit = $this->localPartLimit($domain);
        if ($limit === 0) {
            return [];
        }

        return $this->fittingFirstWords($limit) === [] ? ['random'] : ['random', 'words'];
    }

    public function candidates(string $domain, string $format): iterable
    {
        $limit = $this->localPartLimit($domain);
        if ($format === 'random' && $limit > 0) {
            $length = min(self::RANDOM_LENGTH, $limit);
            $used = [];
            for ($i = 0; $i < self::MAX_CANDIDATES; $i++) {
                $local = Str::lower(Str::random($length));
                if (isset($used[$local])) {
                    $prefix = substr($local, 0, -1);
                    $suffixes = array_filter(str_split('abcdefghijklmnopqrstuvwxyz0123456789'),
                        fn ($suffix) => ! isset($used[$prefix.$suffix]));
                    $local = $prefix.Arr::random($suffixes);
                }
                $used[$local] = true;
                yield $local.'@'.$domain;
            }
        } elseif ($format === 'words') {
            $firstWords = $this->fittingFirstWords($limit);
            $secondsByFirst = [];
            for ($i = 0; $i < self::MAX_CANDIDATES && $firstWords !== []; $i++) {
                $first = Arr::random($firstWords);
                $secondsByFirst[$first] ??= array_filter(config('mailbox_words.second'),
                    fn ($second) => strlen($first) + 1 + strlen($second) <= $limit);
                $second = Arr::random($secondsByFirst[$first]);
                $secondsByFirst[$first] = array_diff($secondsByFirst[$first], [$second]);
                if ($secondsByFirst[$first] === []) {
                    unset($firstWords[$first]);
                }
                yield $first.'.'.$second.'@'.$domain;
            }
        }
    }

    private function fittingFirstWords(int $limit): array
    {
        $seconds = config('mailbox_words.second');
        if ($seconds === []) {
            return [];
        }
        $room = $limit - 1 - min(array_map('strlen', $seconds));
        $firsts = array_filter(config('mailbox_words.first'), fn ($first) => strlen($first) <= $room);

        return array_combine($firsts, $firsts);
    }

    public function generate(string $domain, string $format): Email
    {
        foreach ($this->candidates($domain, $format) as $address) {
            try {
                $issuedAt = now()->toImmutable();

                return DB::transaction(fn () => Email::forceCreate([
                    'email' => $address, 'created_at' => $issuedAt, 'expires_at' => $issuedAt->addDay(),
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }
        throw new HttpResponseException(response()->json([
            'code' => 'mailbox_candidates_exhausted',
            'message' => 'Не удалось подобрать свободный адрес.',
        ], 503));
    }
}
