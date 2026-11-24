<?php

$server = stream_socket_server('tcp://127.0.0.1:0');
echo stream_socket_get_name($server, false)."\n";
flush();
$requests = [];
while (count($requests) < 3 && $socket = stream_socket_accept($server, 5)) {
    stream_set_timeout($socket, 3);
    $request = '';
    while (($line = fgets($socket)) !== false && $line !== "\r\n") {
        $request .= $line;
    }
    $requests[] = $request;
    if (str_starts_with($request, 'GET /redirect ')) {
        fwrite($socket, "HTTP/1.1 302 Found\r\nLocation: //assets.example.com/img?key=fixture\r\nSet-Cookie: private=fixture\r\nConnection: close\r\n\r\n");
    } elseif (str_starts_with($request, 'GET /large ')) {
        fwrite($socket, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
        // A real GIF with one long comment. Only the streaming byte limit should reject it.
        $start = substr(base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='), 0, -1)."\x21\xfe";
        fwrite($socket, dechex(strlen($start))."\r\n".$start."\r\n");
        $chunk = str_repeat("\xff".str_repeat('x', 255), 256);
        for ($i = 0; $i < 33; $i++) {
            if (! @fwrite($socket, "10000\r\n".$chunk."\r\n")) {
                break;
            }
        }
        @fwrite($socket, "2\r\n\x00\x3b\r\n0\r\n\r\n");
    } else {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n\r\n".$gif);
    }
    fclose($socket);
}
echo json_encode($requests)."\n";
