<?php

namespace App\Services;

use App\Models\Email;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MailboxIssuance
{
    public static function issue(Request $request, array $parameters, Closure $create): int
    {
        $key = self::requestKey($request, $parameters['request_id']);
        $hash = hash('sha256', json_encode([
            $parameters['domain'] ?? null, $parameters['local_part'] ?? null,
            isset($parameters['local_part']) ? null : ($parameters['format'] ?? 'random'),
        ], JSON_THROW_ON_ERROR));

        User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail(['id']);
        $previous = DB::table('mailbox_issuances')->where('request_key', $key)->first(['parameters_hash', 'email_id']);
        if ($previous) {
            if ($previous->parameters_hash !== $hash) {
                throw ValidationException::withMessages(['request_id' => 'Этот запрос уже завершён с другими параметрами. Откройте форму заново.']);
            }

            return $previous->email_id;
        }

        $password = Str::random(32);
        $mailbox = $create($password);
        self::store($request, $key, $hash, $mailbox->id, $password);

        return $mailbox->id;
    }

    public static function storeReset(Request $request, int $emailId, string $password): string
    {
        $ticket = (string) Str::uuid();
        DB::table('mailbox_issuances')->where('email_id', $emailId)->whereNull('parameters_hash')->delete();
        self::store($request, self::requestKey($request, $ticket), null, $emailId, $password);

        return $ticket;
    }

    private static function store(Request $request, string $key, ?string $parametersHash, int $emailId, string $password): void
    {
        DB::table('mailbox_issuances')->insert([
            'request_key' => $key, 'parameters_hash' => $parametersHash, 'email_id' => $emailId,
            'session_hash' => hash('sha256', $request->session()->token()),
            'encrypted_password' => Crypt::encryptString($password), 'expires_at' => now()->addMinutes(10),
        ]);
    }

    public static function read(Request $request, int $emailId, string $ticket): ?array
    {
        if (! Str::isUuid($ticket)) {
            return self::readLegacyReset($request, $emailId, $ticket);
        }
        $key = self::requestKey($request, $ticket);

        return DB::transaction(function () use ($request, $key, $emailId) {
            $query = DB::table('mailbox_issuances')->where('request_key', $key)->where('email_id', $emailId)
                ->where('session_hash', hash('sha256', $request->session()->token()))->where('expires_at', '>', now());
            $receipt = $query->lockForUpdate()->first();
            if (! $receipt) {
                return null;
            }
            $password = Crypt::decryptString($receipt->encrypted_password);
            $query->update(['encrypted_password' => null, 'expires_at' => null]);
            $address = Email::whereKey($emailId)->value('email');

            return self::currentCredentials($address, $password);
        });
    }

    private static function readLegacyReset(Request $request, int $emailId, string $ticket): ?array
    {
        $cache = $request->session()->cache();
        $key = 'issued-'.$request->user()->id.'-'.$emailId;
        $encrypted = $cache->get($key);
        if (! $encrypted) {
            return null;
        }
        $receipt = Crypt::decrypt($encrypted);
        if (($receipt['ticket'] ?? null) !== $ticket) {
            return null;
        }
        $cache->forget($key);

        return self::currentCredentials($receipt['email'], $receipt['password']);
    }

    private static function currentCredentials(?string $address, string $password): ?array
    {
        $hash = User::whereEmail($address)->value('password');

        return $hash && Hash::check($password, $hash) ? ['email' => $address, 'password' => $password] : null;
    }

    public static function eraseExpiredPasswords(): void
    {
        DB::table('mailbox_issuances')->where('expires_at', '<=', now())
            ->update(['encrypted_password' => null, 'expires_at' => null]);
    }

    private static function requestKey(Request $request, string $ticket): string
    {
        return hash('sha256', $request->user()->id.'|'.strtolower($ticket));
    }
}
