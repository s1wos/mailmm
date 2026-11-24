<?php

use ZBateson\MailMimeParser\MailMimeParser;

// Only this deterministic scanner response is substituted. Postfix, queue and SMTP transports run normally.
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $valid = $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/checkv2'
        && $_SERVER['HTTP_MTA_NAME'] === 'mx.mailmm.test' && filter_var($_SERVER['HTTP_IP'], FILTER_VALIDATE_IP)
        && str_contains(file_get_contents('php://input'), 'Message-ID:');
    http_response_code($valid ? 200 : 400);
    echo json_encode(['action' => 'no action', 'milter' => ['add_headers' => ['Authentication-Results' => [
        ['value' => 'mx.mailmm.test; spf=none; dkim=none; dmarc=none'],
    ]]]]);
    return;
}

require '/var/www/vendor/autoload.php';
$profile = $argv[1];
$port = ['manual' => 10252, 'auto' => 10253][$profile];
$output = '/tmp/'.$profile.'-capture.jsonl';
$server = stream_socket_server('tcp://127.0.0.1:'.$port);
touch($output);
while ($stream = stream_socket_accept($server, -1)) {
    stream_set_timeout($stream, 15);
    fwrite($stream, "220 e2e-capture ESMTP\r\n");
    $recipients = [];
    while (($line = fgets($stream)) !== false) {
        if (str_starts_with($line, 'EHLO ')) {
            fwrite($stream, "250-e2e-capture\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n");
        } elseif (preg_match('/^RCPT TO:<([^>]+)>/', $line, $match)) {
            $recipients[] = $match[1];
            fwrite($stream, "250 OK\r\n");
        } elseif ($line === "DATA\r\n") {
            fwrite($stream, "354 Continue\r\n");
            $raw = '';
            while (($line = fgets($stream)) !== false && $line !== ".\r\n") {
                $raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            if ($line === false) break;
            $message = (new MailMimeParser)->parse($raw, false);
            $files = [];
            foreach ($message->getAllParts() as $part) {
                if ($name = $part->getFilename()) {
                    $files[$name] = ['bytes' => base64_encode((string) $part->getBinaryContentStream()),
                        'cid' => trim((string) $part->getContentId(), '<>')];
                }
            }
            $capture = ['envelope_to' => $recipients, 'subject' => $message->getSubject(),
                'from' => $message->getHeader('From')?->getEmail(), 'to' => $message->getHeader('To')?->getEmail(),
                'reply_to' => $message->getHeader('Reply-To')?->getEmail(), 'cc' => $message->getHeader('Cc')?->getEmail(),
                'message_id' => trim((string) $message->getHeaderValue('Message-ID'), '<>'), 'files' => $files,
                'html' => $message->getHtmlContent(), 'text' => $message->getTextContent()];
            file_put_contents($output, json_encode($capture, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            fwrite($stream, "250 Accepted\r\n");
            $recipients = [];
        } elseif ($line === "QUIT\r\n") {
            fwrite($stream, "221 Closing\r\n");
            break;
        } else {
            fwrite($stream, "250 OK\r\n");
        }
    }
    fclose($stream);
}
