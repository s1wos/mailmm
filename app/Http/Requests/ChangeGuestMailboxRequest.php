<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Validator;

final class ChangeGuestMailboxRequest
{
    public static function rules(): array
    {
        return [
            'domain' => ['sometimes', 'required', 'string', 'max:252'],
            'format' => ['sometimes', 'required', 'string', 'in:random,words'],
        ];
    }

    public static function validate(array $input): array
    {
        return Validator::validate($input, self::rules());
    }
}
