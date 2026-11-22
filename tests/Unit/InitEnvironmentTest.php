<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class InitEnvironmentTest extends TestCase
{
    public function test_repairs_an_existing_environment_and_preserves_generated_credentials_on_retry(): void
    {
        $project = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir().'/mailmm-init-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->makeDirectory($fixture.'/deploy', 0700, true);
        try {
            $files->copy($project.'/deploy/init-env.php', $fixture.'/deploy/init-env.php');
            $files->copy($project.'/.env.example', $fixture.'/.env.example');
            $init = new Process([PHP_BINARY, 'deploy/init-env.php'], $fixture);
            $init->mustRun();
            $fresh = $files->get($fixture.'/.env');
            self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', str_replace("\r", '', $fresh));
            self::assertMatchesRegularExpression('/^SMTP_LOOKUP_PASSWORD=[a-f0-9]{64}$/m', str_replace("\r", '', $fresh));

            foreach (['DB_PASSWORD= # comment', 'DB_PASSWORD=#literal'] as $ambiguous) {
                $files->put($fixture.'/.env', $ambiguous);
                $init->mustRun();
                $repaired = $files->get($fixture.'/.env');
                self::assertStringStartsWith($ambiguous."\n", $repaired);
                self::assertMatchesRegularExpression('/^PG_ADMIN_PASSWORD=[a-f0-9]{64}$/m', $repaired);
            }

            $files->put($fixture.'/.env', "APP_KEY=\"#existing-key\"\r\nDB_PASSWORD=\"\" # db\r\nOTHER=preserved");
            $init->mustRun();
            $repaired = $files->get($fixture.'/.env');
            self::assertStringContainsString("APP_KEY=\"#existing-key\"\r\n", $repaired);
            self::assertStringContainsString("OTHER=preserved\r\n", $repaired);
            self::assertMatchesRegularExpression('/DB_PASSWORD=[a-f0-9]{64} # db\r\n/', $repaired);
            self::assertMatchesRegularExpression('/PG_ADMIN_PASSWORD=[a-f0-9]{64}\r\n/', $repaired);
            self::assertMatchesRegularExpression('/SMTP_LOOKUP_PASSWORD=[a-f0-9]{64}\r\n/', $repaired);
            self::assertMatchesRegularExpression('/REVERB_APP_KEY=[a-f0-9]{32}\r\n/', $repaired);
            self::assertMatchesRegularExpression('/REVERB_APP_SECRET=[a-f0-9]{64}\r\n/', $repaired);

            $init->mustRun();
            self::assertSame($repaired, $files->get($fixture.'/.env'));
        } finally {
            $files->deleteDirectory($fixture);
        }
    }
}
