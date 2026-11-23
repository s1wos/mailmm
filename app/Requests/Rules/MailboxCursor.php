<?php

namespace App\Requests\Rules;

final class MailboxCursor
{
    public static function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'received_at' => ['required', 'date', 'regex:/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?\z/'],
            '_pointsToNextItems' => ['accepted'],
        ];
    }
}
