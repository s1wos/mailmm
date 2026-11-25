<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PanelMessageRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        $recipient = $this->query('recipient');
        $cursor = $this->query('cursor');

        // Omit invalid optional parameters from the return link.
        return [
            'recipient' => is_string($recipient) && strlen($recipient) <= 254 ? ['string'] : ['exclude'],
            'cursor' => is_string($cursor) && strlen($cursor) <= 2048 ? ['string'] : ['exclude'],
        ];
    }
}
