<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class CreateMailAdmin extends Command
{
    protected $signature = 'mail:admin {email : Administrator login}';

    protected $description = 'Create an administrator account with an interactively entered password';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || User::whereEmail($email)->exists()) {
            $this->error('Supply a valid email address that is not already registered.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (at least 12 characters)');
        if (! is_string($password) || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72 || $password !== $this->secret('Confirm password')) {
            $this->error('Enter matching passwords of at least 12 characters and at most 72 bytes.');

            return self::FAILURE;
        }

        $admin = new User(['name' => $email, 'email' => $email, 'password' => $password]);
        $admin->is_admin = true;
        try {
            $admin->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('This address is already registered.');

            return self::FAILURE;
        }
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
