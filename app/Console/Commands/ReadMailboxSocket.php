<?php

namespace App\Console\Commands;

use App\Services\MailboxSocketRead;
use Illuminate\Console\Command;

class ReadMailboxSocket extends Command
{
    protected $signature = 'mail:socket-read';

    protected $description = 'Read one authorized mailbox command from the Reverb worker pipe';

    public function handle(MailboxSocketRead $reader): int
    {
        $input = stream_get_contents(STDIN, 8193);
        if (! $input || strlen($input) > 8192) {
            return self::INVALID;
        }
        $result = $reader->handle(json_decode($input, true, flags: JSON_THROW_ON_ERROR));
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
