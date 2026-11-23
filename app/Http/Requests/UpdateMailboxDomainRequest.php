<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailboxDomainRequest extends FormRequest
{
    public function rules(): array
    {
        return ['issuance_enabled' => ['required', 'boolean']];
    }
}
