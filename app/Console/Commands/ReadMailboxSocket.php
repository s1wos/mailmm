<?php

namespace App\Console\Commands;

use App\Console\Requests\MailboxSocketJobRequest;
use App\Services\MailboxSocketRead;
use Illuminate\Console\Command;

class ReadMailboxSocket extends Command
{
    protected $signature = 'mail:socket-read {--worker : Read one JSON job per line until the pipe closes}';

    protected $description = 'Read authorized mailbox commands from the Reverb worker pipe';

    public function handle(MailboxSocketRead $reader): int
    {
        if ($this->option('worker')) {
            while (($line = fgets(STDIN, MailboxSocketJobRequest::MAX_BYTES + 2)) !== false) {
                if (! str_ends_with($line, "\n") || ! $this->reply($reader, substr($line, 0, -1), "\n")) {
                    return self::INVALID;
                }
            }

            return self::SUCCESS;
        }

        $input = (string) stream_get_contents(STDIN, MailboxSocketJobRequest::MAX_BYTES + 1);

        return $this->reply($reader, $input) ? self::SUCCESS : self::INVALID;
    }

    private function reply(MailboxSocketRead $reader, string $input, string $separator = ''): bool
    {
        $job = json_decode($input, true);
        if (! MailboxSocketJobRequest::isValid($job, strlen($input))) {
            return false;
        }

        $output = json_encode($reader->handle($job), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).$separator;

        return fwrite(STDOUT, $output) === strlen($output);
    }
}
