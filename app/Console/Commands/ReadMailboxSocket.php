<?php

namespace App\Console\Commands;

use App\Console\Requests\MailboxSocketJobRequest;
use App\Services\MailboxSocketRead;
use Illuminate\Console\Command;

class ReadMailboxSocket extends Command
{
    protected $signature = 'mail:socket-read';

    protected $description = 'Read one authorized mailbox command from the Reverb worker pipe';

    public function handle(MailboxSocketRead $reader): int
    {
        $input = (string) stream_get_contents(STDIN, MailboxSocketJobRequest::MAX_BYTES + 1);
        $job = json_decode($input, true);
        if (! MailboxSocketJobRequest::isValid($job, strlen($input))) {
            return self::INVALID;
        }
        $result = $reader->handle($job);
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
