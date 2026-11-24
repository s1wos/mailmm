<?php

// Isolated one-connection server. Capture the envelope and the bytes a DATA receiver would store.
$server = stream_socket_server('tcp://127.0.0.1:0');
echo stream_socket_get_name($server, false)."\n";
flush();
$stream = stream_socket_accept($server, 10);
if (! $stream) {
    exit(1);
}
stream_set_timeout($stream, 10);
$mode = $argv[1];
fwrite($stream, "220 fixture ESMTP\r\n");
$commands = [];
$body = '';
while (($command = fgets($stream)) !== false) {
    $commands[] = rtrim($command, "\r\n");
    if (str_starts_with($command, 'EHLO ')) {
        fwrite($stream, $mode === 'ascii' ? "250 fixture\r\n" : "250-fixture\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n");
    } elseif (str_starts_with($command, 'RCPT ') && str_starts_with($mode, 'recipient')) {
        $reply = match ($mode) {
            'recipient' => '550 5.1.1 mailbox unknown',
            'recipient-policy' => '550 5.7.1 message blocked; support reference 5.1.1',
            'recipient-content' => '554 5.6.0 content unsupported',
            'recipient-ambiguous' => "550-5.7.1 policy\r\n550 5.1.1 mailbox unknown",
            default => '550 unavailable',
        };
        fwrite($stream, $reply."\r\n");
    } elseif ($command === "DATA\r\n") {
        fwrite($stream, "354 send message\r\n");
        while (($line = fgets($stream)) !== false && $line !== ".\r\n") {
            $body .= str_starts_with($line, '..') ? substr($line, 1) : $line;
        }
        if ($mode === 'disconnect') {
            break;
        }
        $reply = match ($mode) {
            'defer' => '451 temporarily unavailable',
            'data-policy' => '550 5.7.1 message rejected',
            'data-recipient' => '550 5.2.1 mailbox disabled',
            default => '250 accepted',
        };
        fwrite($stream, $reply."\r\n");
    } elseif ($command === "QUIT\r\n") {
        fwrite($stream, "221 bye\r\n");
        break;
    } else {
        fwrite($stream, "250 OK\r\n");
    }
}
fclose($stream);
fclose($server);
echo json_encode(['commands' => $commands, 'body' => base64_encode($body)])."\n";
