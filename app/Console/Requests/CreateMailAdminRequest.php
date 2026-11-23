<?php

namespace App\Console\Requests;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CreateMailAdminRequest
{
    private const PASSWORD_ERROR = 'Enter matching passwords of at least 12 characters and at most 72 bytes.';

    public static function validateEmail(string $email): void
    {
        Validator::validate(['email' => $email], [
            'email' => ['bail', 'required', 'email:filter', 'max:254', Rule::unique(User::class, 'email')],
        ], ['email.*' => 'Supply a valid email address that is not already registered.']);
    }

    public static function validatePassword(mixed $password): void
    {
        if (! is_string($password) || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
            throw ValidationException::withMessages(['password' => self::PASSWORD_ERROR]);
        }

    }

    public static function validateConfirmation(string $password, mixed $confirmation): void
    {
        if ($password !== $confirmation) {
            throw ValidationException::withMessages(['password' => self::PASSWORD_ERROR]);
        }
    }
}
