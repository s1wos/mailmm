<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MailboxChannelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A[0-9]+\.[0-9]+\z/'],
            'channel_name' => ['required', 'string', 'max:64'],
        ];
    }
}
