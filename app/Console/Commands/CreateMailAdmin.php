<?php

namespace App\Console\Commands;

use App\Console\Requests\CreateMailAdminRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateMailAdmin extends Command
{
    protected $signature = 'mail:admin {email : Administrator login}';

    protected $description = 'Create an administrator account with an interactively entered password';

    public function handle(): int
    {
        try {
            $email = Str::lower(trim($this->argument('email')));
            CreateMailAdminRequest::validateEmail($email);
            $password = $this->secret('Password (at least 12 characters)');
            CreateMailAdminRequest::validatePassword($password);
            CreateMailAdminRequest::validateConfirmation($password, $this->secret('Confirm password'));
        } catch (ValidationException $error) {
            $this->error($error->getMessage());

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
