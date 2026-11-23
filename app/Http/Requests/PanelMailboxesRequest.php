<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PanelMailboxesRequest extends FormRequest
{
    public function validationData(): array
    {
        return [...$this->all(), 'ticket' => $this->query('ticket')];
    }

    public function rules(): array
    {
        if (! $this->wantsJson()) {
            return [];
        }

        return [
            'issued' => ['nullable', 'integer', 'min:1'],
            'ticket' => is_string($this->query('ticket')) ? ['string'] : ['exclude'],
        ];
    }
}
