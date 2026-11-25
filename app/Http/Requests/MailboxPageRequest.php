<?php

namespace App\Http\Requests;

use App\Requests\Rules\MailboxCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;

class MailboxPageRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->query() + $this->only('cursor');
    }

    public function rules(): array
    {
        return ['cursor' => $this->hasHeader('X-Inbox-Until') ? ['required', 'string'] : ['sometimes', 'required', 'string']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $encoded = $validator->getData()['cursor'] ?? null;
            if ($encoded !== null && $validator->errors()->isEmpty()) {
                $cursor = Cursor::fromEncoded($encoded);
                $rules = MailboxCursor::rules($this->hasHeader('X-Inbox-Until'));
                $validator->errors()->merge(ValidatorFactory::make($cursor?->toArray() ?? [], $rules)->errors());
            }
        }];
    }
}
