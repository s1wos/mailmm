<?php

namespace App\Http\Requests;

use App\Rules\MailboxDomainName;
use Illuminate\Foundation\Http\FormRequest;

class StoreMailboxDomainRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', new MailboxDomainName]];
    }

    public function messages(): array
    {
        return ['name.required' => 'Введите почтовый домен.'];
    }
}
