<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root.'/.env';
umask(0077);
$lock = fopen($root.'/.env.init.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX)) {
    throw new RuntimeException('Could not lock .env initialization.');
}
$exists = is_file($path);
$original = file_get_contents($exists ? $path : $root.'/.env.example');
if ($original === false) {
    throw new RuntimeException('Could not read the environment configuration.');
}
$contents = $original;
$newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

$secrets = [
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_PASSWORD' => bin2hex(random_bytes(32)),
    'PG_ADMIN_PASSWORD' => bin2hex(random_bytes(32)),
    'SMTP_LOOKUP_PASSWORD' => bin2hex(random_bytes(32)),
    'REVERB_APP_KEY' => bin2hex(random_bytes(16)),
    'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
];
foreach ($secrets as $name => $value) {
    $assignment = '/^([ \t]*(?:export[ \t]+)?'.$name.'[ \t]*=[ \t]*)([^\r\n]*)/m';
    $contents = preg_replace_callback($assignment, static function (array $match) use ($value): string {
        if (! preg_match('/^(?:[ \t]*|(?:""|\'\')(?:[ \t]+(#.*))?[ \t]*)$/D', $match[2], $empty)) {
            return $match[0];
        }

        return $match[1].$value.(isset($empty[1]) ? ' '.$empty[1] : '');
    }, $contents, count: $found);
    if ($found === 0) {
        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= $newline;
        }
        $contents .= $name.'='.$value.$newline;
    }
}
if ($exists && $contents === $original) {
    fwrite(STDOUT, "All required credentials are already configured.\n");
    exit(0);
}
$temporary = tempnam($root, '.env.init-');
if ($temporary === false) {
    throw new RuntimeException('Could not create a temporary environment file.');
}
try {
    if (file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $path)) {
        throw new RuntimeException('Could not save the environment configuration.');
    }
} finally {
    if (file_exists($temporary)) {
        unlink($temporary);
    }
}
fwrite(STDOUT, "Generated missing credentials in .env.\n");
