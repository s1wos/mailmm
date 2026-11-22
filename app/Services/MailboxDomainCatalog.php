<?php

namespace App\Services;

use App\Exceptions\NoIssuableDomains;
use App\Models\MailboxDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Random\Randomizer;

class MailboxDomainCatalog
{
    public function __construct(
        private readonly EmailGeneratorService $generator,
        private readonly Randomizer $randomizer,
    ) {}

    private function domainQuery(bool $includeReceiveOnly): Builder
    {
        $query = MailboxDomain::query();
        if ($includeReceiveOnly) {
            $query->orderByDesc('issuance_enabled');
        } else {
            $query->where('issuance_enabled', true);
        }

        return $query->orderBy('id');
    }

    public function options(bool $includeReceiveOnly = false): array
    {
        return $this->domainQuery($includeReceiveOnly)->get()
            ->map(fn (MailboxDomain $domain) => [
                'domain' => $domain->name,
                'formats' => $this->generator->formatsFor($domain->name),
            ])->all();
    }

    public function forIssuance(
        ?string $domain, string $format, int $minLocalPartBytes = 1, bool $includeReceiveOnly = false,
    ): MailboxDomain {
        $query = $this->domainQuery($includeReceiveOnly);
        if ($domain !== null) {
            $query->where('name', $domain);
        }
        $domains = $query->sharedLock()->get();
        if ($domains->isEmpty()) {
            if ($domain !== null) {
                throw ValidationException::withMessages(['domain' => 'Этот домен недоступен для выдачи.']);
            }
            throw new NoIssuableDomains;
        }

        if ($includeReceiveOnly && $domain === null && $domains->contains('issuance_enabled', true)) {
            $domains = $domains->where('issuance_enabled', true);
        }

        $eligible = $domains->filter(fn ($row) => in_array($format, $this->generator->formatsFor($row->name), true));
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages(['format' => 'Выбранный формат адреса недоступен.']);
        }
        $eligible = $eligible->filter(fn ($row) => $this->generator->localPartLimit($row->name) >= $minLocalPartBytes)->values();
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages(['local_part' => 'Имя слишком длинное для выбранного домена.']);
        }

        return $eligible->count() === 1 ? $eligible->first() : $eligible[$this->randomizer->getInt(0, $eligible->count() - 1)];
    }
}
