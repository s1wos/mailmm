<?php

namespace App\Http\Requests;

use App\Rules\MailboxDomainName;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreMailboxDomainRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'bail', 'required', 'string', new MailboxDomainName,
                static function (string $attribute, string $value, Closure $fail): void {
                    if (MailboxDomainName::parse($value) === config('forwarding.bounce_domain')) {
                        $fail('Этот домен зарезервирован для уведомлений о доставке.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Введите почтовый домен.'];
    }
}
