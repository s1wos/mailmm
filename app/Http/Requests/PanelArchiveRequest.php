<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PanelArchiveRequest extends FormRequest
{
    public function rules(): array
    {
        return $this->wantsJson() ? [
            'recipient' => ['nullable', 'string', 'max:254'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ] : [];
    }
}
