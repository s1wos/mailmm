<?php

namespace App\Models;

use App\Rules\MailboxDomainName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MailboxDomain extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'issuance_enabled'];

    public static function forSettings(): Collection
    {
        return static::orderBy('name')->get(['id', 'name', 'issuance_enabled']);
    }

    protected function casts(): array
    {
        return ['issuance_enabled' => 'boolean'];
    }

    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value) => MailboxDomainName::parse($value)
            ?? throw ValidationException::withMessages(['name' => MailboxDomainName::INVALID_NAME]));
    }
}
