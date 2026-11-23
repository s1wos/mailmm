<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestIssuanceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['guest_issuance_enabled' => ['required', 'boolean']];
    }
}
