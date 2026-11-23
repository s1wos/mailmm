<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'regex:/\A.{1,72}\z/sD', 'not_regex:/\x00/', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Новый пароль должен содержать минимум 12 символов.',
            'password.regex' => 'Пароль должен занимать не более 72 байт.',
            'password.not_regex' => 'Пароль содержит недопустимый нулевой байт.',
            'password.confirmed' => 'Подтверждение не совпадает с новым паролем.',
        ];
    }
}
