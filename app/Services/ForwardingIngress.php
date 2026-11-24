<?php

namespace App\Services;

use App\Exceptions\ForwardingCheckFailed;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ForwardingIngress
{
    /** Values come from local Postfix pipe attributes, never from the RFC message headers. */
    public static function context(?string $sender, ?string $ip, ?string $helo): ?array
    {
        if ($sender === null || ! filter_var($ip, FILTER_VALIDATE_IP) || ! is_string($helo)
            || $helo === '' || strlen($helo) > 255 || strlen($sender) > 256
            || preg_match('/[\x00-\x1f\x7f]/', $sender.$helo)) {
            return null;
        }

        return ['sender' => $sender === '<>' ? '' : $sender, 'ip' => $ip, 'helo' => $helo];
    }

    public static function suppressAutomatic(string $raw): bool
    {
        $headers = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $headers);

        return in_array(ForwardingMarker::type($raw), ['auto', 'verification'], true)
            || (bool) preg_match('~^Content-Type:[ \t]*(?:message/(?:global-)?delivery-status\b|multipart/report\b[^\r\n]*\breport-type\s*=\s*"?(?:global-)?delivery-status\b)~im', $headers);
    }

    public function authenticationResults(Message $message, string $canonicalRaw): string
    {
        $context = $message->forwarding_context;
        if (! isset($context['sender'], $context['helo'], $context['ip']) || $context['sender'] === '') {
            throw new ForwardingCheckFailed('ingress_unavailable');
        }
        $authserv = (string) config('forwarding.authserv_id');
        if (! filter_var($authserv, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new ForwardingCheckFailed('ingress_unavailable');
        }
        // Never attribute a sender-supplied result to this MX or its ARC sealer.
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', preg_split('/\r?\n\r?\n/', $canonicalRaw, 2)[0]);
        // An upstream result may legitimately mention our domain in method properties.
        // Only the first field element identifies the authority claiming the result.
        preg_match_all('/^Authentication-Results:([^\r\n]*)/im', $headers, $matches);
        foreach ($matches[1] as $field) {
            // Strip RFC comments, including nested comments and backslash-escaped parentheses.
            $bare = preg_replace('~(\((?:[^()\\\\]++|\\\\.|(?1))*\))~', ' ', $field);
            if ($bare !== null && preg_match('/^\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([a-z0-9.-]+))\s*(?:[0-9]+\s*)?;/i', $bare, $authority)) {
                $id = ($authority[1] ?? '') !== '' ? preg_replace('/\\\\(.)/', '$1', $authority[1]) : ($authority[2] ?? '');
                if (strcasecmp(rtrim($id, '.'), rtrim($authserv, '.')) !== 0) {
                    continue;
                }
            }
            throw new ForwardingCheckFailed('untrusted_authentication_results');
        }
        if (isset($context['authentication_results'])) {
            return $context['authentication_results'];
        }
        $url = config('forwarding.scanner_url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new ForwardingCheckFailed('scanner_unavailable', true);
        }
        try {
            $response = Http::connectTimeout(2)->timeout(18)->withoutRedirecting()->withHeaders([
                'IP' => $context['ip'], 'Helo' => $context['helo'], 'From' => $context['sender'],
                'Rcpt' => $message->recipient, 'MTA-Name' => $authserv, 'Flags' => 'milter,no_log,pass_all',
            ])->withBody($canonicalRaw, 'message/rfc822')->post($url);
        } catch (ConnectionException|\InvalidArgumentException) {
            throw new ForwardingCheckFailed('scanner_unavailable', true);
        }
        if (! $response->successful() || ! is_string($action = $response->json('action'))) {
            throw new ForwardingCheckFailed('scanner_unavailable', true);
        }
        $symbols = $response->json('symbols');
        if (isset($symbols['CLAM_VIRUS'])) {
            throw new ForwardingCheckFailed('blocked_by_filter');
        }
        if (isset($symbols['CLAM_VIRUS_FAIL'])) {
            throw new ForwardingCheckFailed('scanner_unavailable', true);
        }
        if (in_array($action, ['soft reject', 'greylist'], true)) {
            throw new ForwardingCheckFailed('scanner_deferred', true);
        }
        $field = $response->json('milter.add_headers.Authentication-Results');
        $value = $field[0]['value'] ?? $field['value'] ?? null;
        $value = is_string($value) ? trim(preg_replace('/\r?\n[ \t]+/', ' ', $value)) : '';
        if ($action !== 'no action' && $value === '') {
            throw new ForwardingCheckFailed('blocked_by_filter');
        }
        if (strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)
            || strcasecmp(trim(explode(';', $value, 2)[0]), $authserv) !== 0 || ! str_contains($value, ';')) {
            throw new ForwardingCheckFailed('scanner_unavailable', true);
        }
        if (preg_match('/\b(?:spf|dkim|dmarc|arc)=temperror\b/i', $value)) {
            throw new ForwardingCheckFailed('scanner_deferred', true);
        }
        if ($action !== 'no action') {
            throw new ForwardingCheckFailed('blocked_by_filter');
        }

        return $value;
    }
}
