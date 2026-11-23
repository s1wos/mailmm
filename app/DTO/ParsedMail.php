<?php

namespace App\DTO;

readonly class ParsedMail
{
    /** @param list<array{filename: string, mime: string, content: string}> $attachments */
    public function __construct(
        public string $from,
        public ?string $subject,
        public ?string $bodyText,
        public array $attachments,
    ) {}
}
