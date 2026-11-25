<?php

$server = stream_socket_server('tcp://0.0.0.0:2525');
if ($server === false) {
    exit(1);
}

while ($stream = stream_socket_accept($server, -1)) {
    stream_set_timeout($stream, 10);
    fwrite($stream, "220 relay-sink ESMTP\r\n");
    $recipients = [];
    while (($line = fgets($stream)) !== false) {
        if (str_starts_with($line, 'EHLO ')) {
            fwrite($stream, "250-relay-sink\r\n250 8BITMIME\r\n");
        } elseif (str_starts_with($line, 'MAIL FROM:') || $line === "RSET\r\n") {
            $recipients = [];
            fwrite($stream, "250 OK\r\n");
        } elseif (preg_match('/^RCPT TO:<((?:archive|original)@reader\.test)>/i', $line, $match)) {
            $recipients[] = $match[1];
            fwrite($stream, "250 OK\r\n");
        } elseif ($line === "DATA\r\n" && $recipients !== []) {
            fwrite($stream, "354 Continue\r\n");
            $raw = '';
            while (($line = fgets($stream)) !== false && $line !== ".\r\n") {
                $raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            if ($line === false) {
                break;
            }
            $capture = json_encode(['received_at' => microtime(true), 'recipients' => $recipients, 'raw' => base64_encode($raw)], JSON_THROW_ON_ERROR);
            file_put_contents('/tmp/relay-captures.jsonl', $capture."\n", FILE_APPEND | LOCK_EX);
            fwrite($stream, "250 Accepted\r\n");
        } elseif ($line === "QUIT\r\n") {
            fwrite($stream, "221 Closing\r\n");
            break;
        } else {
            fwrite($stream, "550 Unexpected command or recipient\r\n");
        }
    }
    fclose($stream);
}
