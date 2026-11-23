<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;

class PanelErrorLogRequest extends FormRequest
{
    public function validationData(): array
    {
        return ['cursor' => $this->query('cursor') ?? $this->input('cursor')];
    }

    public function rules(): array
    {
        if (! $this->wantsJson()) {
            return [];
        }

        return ['cursor' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
            $cursor = Cursor::fromEncoded($value);
            if (Validator::make($cursor?->toArray() ?? [], [
                'occurred_at' => ['required', 'string', 'date'],
                'id' => ['required', 'integer:strict', 'min:1'],
                '_pointsToNextItems' => ['required', 'boolean:strict'],
            ])->fails()) {
                $fail('Некорректная страница журнала.');
            }
        }]];
    }
}
