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

        // An invalid optional filter is omitted from the return link.
        return ['recipient' => is_string($recipient) && strlen($recipient) <= 254 ? ['string'] : ['exclude']];
    }
}
