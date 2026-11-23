<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePermanentMailboxRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'uuid'],
            ...ChangeGuestMailboxRequest::rules(),
            'local_part' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.*' => 'Обновите страницу, чтобы начать новую выдачу.',
            'local_part.max' => 'Максимальная длина имени — 64 символа.',
            'local_part.regex' => 'Используйте строчные латинские буквы и цифры. Внутри имени допустимы точка, дефис и подчёркивание.',
            'domain.*' => 'Выберите доступный почтовый домен.',
            'format.*' => 'Выберите один из доступных форматов имени.',
        ];
    }
}
