<?php

namespace App\Requests\Rules;

final class MailboxCursor
{
    public static function rules(bool $delta = false): array
    {
        $fields = $delta ? ['inbox_revision' => ['required', 'integer', 'min:1']] : [
            'id' => ['required', 'integer', 'min:1'],
            'received_at' => ['required', 'date', 'regex:/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?\z/'],
        ];

        return $fields + ['_pointsToNextItems' => ['accepted']];
    }
}
