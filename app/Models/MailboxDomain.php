<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MailboxDomain extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'issuance_enabled'];

    protected function casts(): array
    {
        return ['issuance_enabled' => 'boolean'];
    }

    protected function name(): Attribute
    {
        return Attribute::set(function (string $value): string {
            $name = strtolower(trim($value, " \t\n\r\v\f"));
            if (str_ends_with($name, '.')) {
                $name = substr($name, 0, -1);
            }
            $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
            if (strlen($name) > 252 || ! preg_match("/\\A{$label}(?:\\.{$label})+\\z/", $name)) {
                throw ValidationException::withMessages([
                    'name' => 'Введите домен длиной до 252 символов с двумя или более DNS-метками. Для кириллицы используйте Punycode.',
                ]);
            }

            return $name;
        });
    }
}
