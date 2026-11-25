<?php

namespace Tests\Feature;

use App\Console\Requests\MailboxSocketJobRequest;
use App\Models\Email;
use App\Models\User;
use App\Services\MailboxSocketGrant;
use App\Services\MailboxSocketPool;
use App\Services\MailboxSocketRead;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Application;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventDispatcher;
use Laravel\Reverb\Protocols\Pusher\Server;
use Laravel\Reverb\Servers\Reverb\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\Loop;
use ReflectionProperty;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MailboxSocketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'test', 'secret' => 'secret', 'app_id' => 'mailmm',
        ]]);
    }

    private function grant(int $id, string $socket = '123.456'): array
    {
        $channel = 'private-mailbox.'.$id;
        $auth = $this->postJson('/api/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel])
            ->assertOk()->assertJsonStructure(['channel_data']);
        $data = json_decode($auth->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('grantExpiresAt', $data);

        return ['grant' => $data['grant'], 'socket' => $socket, 'channel' => $channel];
    }

    private function read(array $context, array $data = [], string $command = 'mailbox.check'): array
    {
        return app(MailboxSocketRead::class)->handle([...$context, 'command' => $command,
            'data' => [...['requestId' => (string) str()->uuid(), 'reason' => 'manual'], ...$data]]);
    }

    public function test_worker_validates_the_binding_and_payload_for_each_command(): void
    {
        $binding = ['grant' => 'opaque', 'socket' => '1.2', 'channel' => 'private-mailbox.1'];
        $authorize = $binding + ['command' => 'mailbox.authorize'];
        $check = $binding + ['command' => 'mailbox.check', 'data' => ['requestId' => 'check', 'reason' => 'auto']];
        $page = $binding + ['command' => 'mailbox.page', 'data' => ['requestId' => 'next', 'continuation' => 'opaque']];
        foreach ([$authorize, $check, $check + ['automatic' => true], $page] as $job) {
            $this->assertTrue(MailboxSocketJobRequest::isValid($job, strlen(json_encode($job))));
        }
        foreach ([null, false, [], $binding, [...$authorize, 'socket' => []], [...$authorize, 'command' => 'unknown'],
            $check + ['automatic' => 'false'], [...$check, 'data' => 'wrong type'], [...$check, 'data' => $page['data']],
            [...$page, 'data' => $check['data']]] as $job) {
            $this->assertFalse(MailboxSocketJobRequest::isValid($job, strlen(json_encode($job))));
        }
        $this->assertFalse(MailboxSocketJobRequest::isValid($authorize, MailboxSocketJobRequest::MAX_BYTES + 1));
    }

    public function test_socket_worker_reads_current_mailbox_access_for_each_frame(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'mailmm-socket-');
        $this->assertIsString($database);
        config(['database.connections.socket_worker' => ['driver' => 'sqlite', 'database' => $database],
            'cache.limiter' => 'file', 'session.driver' => 'cookie']);
        $input = new InputStream;
        $php = [PHP_BINARY];
        if (PHP_OS_FAMILY === 'Windows' && (new Process([PHP_BINARY, '-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);']))->run() !== 0) {
            $php = [PHP_BINARY, '-d', 'extension=pdo_sqlite'];
        }
        $worker = new Process([...$php, base_path('artisan'), 'mail:socket-read', '--worker', '--no-ansi'], base_path(), [
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'file', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
            'DB_URL' => '', 'SESSION_DRIVER' => 'array',
        ], $input, 20);
        $grantKey = null;
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'socket_worker', '--force' => true]));
            $first = Email::on('socket_worker')->create(['email' => 'first@test.invalid', 'expires_at' => now()->addDay()]);
            $second = Email::on('socket_worker')->create(['email' => 'second@test.invalid', 'expires_at' => now()->addDay()]);
            $first->messages()->create(['recipient' => $first->email, 'from' => 'sender@test.invalid',
                'subject' => 'Private first', 'received_at' => now()]);
            $second->messages()->create(['recipient' => $second->email, 'from' => 'sender@test.invalid',
                'subject' => 'Private second', 'received_at' => now()]);
            $session = new Store('mailmm_socket', new ArraySessionHandler(120));
            $session->start();
            $session->put('email_id', $first->id);
            $request = Request::create('/');
            $request->setLaravelSession($session);
            $grant = MailboxSocketGrant::issue($request, $first, '1.2')['grant'];
            $grantKey = MailboxSocketGrant::CACHE_PREFIX.'session:'.explode('.', $grant, 2)[0];

            $worker->start();
            $check = function (Email $mailbox, string $requestId) use ($grant, $input, $worker): array {
                $job = ['grant' => $grant, 'socket' => '1.2', 'channel' => 'private-mailbox.'.$mailbox->id,
                    'command' => 'mailbox.check', 'automatic' => true, 'data' => ['requestId' => $requestId, 'reason' => 'auto']];
                $input->write(json_encode($job, JSON_THROW_ON_ERROR)."\n");
                $reply = '';
                $received = $worker->waitUntil(static function (string $type, string $chunk) use (&$reply) {
                    if ($type === Process::OUT) {
                        $reply .= $chunk;
                    }

                    return str_ends_with($reply, "\n");
                });
                $this->assertTrue($received, $worker->getErrorOutput());
                $this->assertTrue($worker->isRunning());

                return json_decode($reply, true, flags: JSON_THROW_ON_ERROR);
            };
            $reply = $check($first, 'first');
            $this->assertSame('first', $reply['requestId']);
            $this->assertSame('Private first', $reply['page']['data'][0]['subject']);
            $this->assertSame(['requestId' => 'wrong', 'code' => 'access_revoked'], $check($second, 'wrong'));
            $first->update(['closed_at' => now()]);
            $this->assertSame(['requestId' => 'closed', 'code' => 'access_revoked'], $check($first, 'closed'));
        } finally {
            $input->close();
            $worker->stop(0);
            if ($grantKey !== null) {
                Cache::store('file')->forget($grantKey);
            }
            DB::purge('socket_worker');
            unlink($database);
        }
    }

    public function test_socket_pool_reuses_a_worker_between_sequential_commands(): void
    {
        $pool = new MailboxSocketPool;
        $idle = new ReflectionProperty($pool, 'idle');
        $read = static function (string $requestId) use ($pool): array {
            $reply = [];
            $pool->submit(['grant' => 'missing.group', 'socket' => '1.2', 'channel' => 'private-mailbox.1',
                'command' => 'mailbox.check', 'data' => ['requestId' => $requestId, 'reason' => 'auto']],
                static function (array $result) use (&$reply): void {
                    $reply = $result;
                });
            Loop::run();

            return $reply;
        };

        $this->assertSame(['requestId' => 'first', 'code' => 'access_revoked'], $read('first'));
        $workers = $idle->getValue($pool);
        $this->assertCount(1, $workers);
        $this->assertTrue($workers[0]->isRunning());
        $this->assertSame(['requestId' => 'second', 'code' => 'access_revoked'], $read('second'));
        $this->assertSame($workers, $idle->getValue($pool));
    }

    private function wire(): WebSocketConnection
    {
        return new class implements WebSocketConnection
        {
            public array $frames = [];

            public bool $closed = false;

            public function id(): int
            {
                return spl_object_id($this);
            }

            public function close(mixed $message = null): void
            {
                $this->closed = true;
            }

            public function send(mixed $message): void
            {
                $this->frames[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            }
        };
    }

    private function subscribe(Application $app, string $channel): array
    {
        $wire = $this->wire();
        $connection = new Connection($wire, $app, 'http://localhost');
        $server = app(Server::class);
        $server->open($connection);
        $auth = $this->postJson('/api/broadcasting/auth', ['socket_id' => $connection->id(), 'channel_name' => $channel])->assertOk()->json();
        $subscription = json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, ...$auth]]);
        $server->message($connection, $subscription);
        $this->assertSame('pusher_internal:subscription_succeeded', end($wire->frames)['event']);
        $wire->frames = [];

        return [$wire, $connection, $subscription];
    }

    private function startReverb(): Application
    {
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            fn (array $job, Closure $complete) => $complete(app(MailboxSocketRead::class)->handle($job))
        );
        Factory::makePusherRouter('');

        return new Application('mailmm', 'test', 'secret', 60, 30, ['*'], 10000);
    }

    public function test_only_current_subscriptions_receive_mail_and_a_delayed_revocation_preserves_new_credentials(): void
    {
        $app = $this->startReverb();
        $owner = User::factory()->create();
        $mailbox = Email::create(['email' => 'owner@test.invalid', 'owner_user_id' => $owner->id]);
        $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $channel = 'private-mailbox.'.$mailbox->id;
        [$oldWire, $old, $oldSubscription] = $this->subscribe($app, $channel);
        $broadcast = fn (string $event, array $data) => EventDispatcher::dispatchSynchronously($app, [
            'event' => $event, 'channel' => $channel, 'data' => json_encode($data),
        ]);
        $broadcast('message.received', ['id' => 1]);
        $this->assertSame(['message.received'], array_column($oldWire->frames, 'event'));
        $oldWire->frames = [];
        $owner->forceFill(['password' => 'replacement-password'])->save();
        app('session')->driver()->migrate();
        app('session')->driver()->forget('password_hash_web');
        $this->withCookie(config('session.cookie'), app('session')->getId());
        [$newWire, $new] = $this->subscribe($app, $channel);
        $broadcast('message.received', ['id' => 2]);
        $this->assertSame([], $oldWire->frames);
        $this->assertSame(['message.received'], array_column($newWire->frames, 'event'));
        $members = app(ChannelManager::class)->for($app)->find($channel);
        $this->assertFalse($members->subscribed($old));
        $broadcast('message.received', ['id' => 3]);
        $this->assertSame([], $oldWire->frames);
        $broadcast('mailbox.access-revoked', []);
        $this->assertSame([['event' => 'mailbox.access-revoked', 'channel' => $channel, 'data' => '[]']], $oldWire->frames);
        $this->assertTrue($members->subscribed($new));
        app(Server::class)->message($old, $oldSubscription);
        $this->assertFalse($members->subscribed($old));
        $this->assertSame('pusher:subscription_error', end($oldWire->frames)['event']);
        $broadcast('message.received', ['id' => 4]);
        $this->assertSame(['message.received', 'message.received', 'message.received'], array_column($newWire->frames, 'event'));
        $owner->forceFill(['password' => 'another-password'])->save();
        $broadcast('mailbox.access-revoked', []);
        $this->assertSame('mailbox.access-revoked', end($newWire->frames)['event']);
        $this->assertFalse($members->subscribed($new));
    }

    public function test_logout_and_expiry_remove_members_before_delivery(): void
    {
        $app = $this->startReverb();
        config(['session.lifetime' => 1]);
        $owner = User::factory()->create();
        $mailbox = Email::create(['email' => 'owner@test.invalid', 'owner_user_id' => $owner->id]);
        $channel = 'private-mailbox.'.$mailbox->id;
        foreach (['logout', 'expiry'] as $cause) {
            $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id]);
            $this->withCookie(config('session.cookie'), app('session')->getId());
            [$wire, $connection] = $this->subscribe($app, $channel);
            if ($cause === 'logout') {
                $this->post('/admin/logout')->assertRedirect();
            } else {
                $this->travel(61)->seconds();
            }
            EventDispatcher::dispatchSynchronously($app, ['event' => 'message.received', 'channel' => $channel, 'data' => '{"id":9}']);
            $this->assertSame([], $wire->frames, $cause);
            $this->assertNull(app(ChannelManager::class)->for($app)->find($channel)?->find($connection), $cause);
            app(Server::class)->close($connection);
        }
    }

    public function test_repeated_authorization_reuses_a_grant_and_reconnects_bound_storage_until_logout(): void
    {
        config(['cache.limiter' => 'database']);
        $owner = User::factory()->create();
        $mailbox = Email::create(['email' => 'owner@test.invalid', 'owner_user_id' => $owner->id]);
        $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $records = fn () => DB::table('cache')->where('key', 'like', '%'.MailboxSocketGrant::CACHE_PREFIX.'%')->count();
        $first = $this->grant($mailbox->id);
        $this->travel(1)->seconds();
        $this->assertSame($first, $this->grant($mailbox->id));
        $contexts = [];
        foreach (range(1, 24) as $i) {
            $contexts[] = $this->grant($mailbox->id, '101.'.$i);
        }
        $this->assertSame(1, $records());
        $authorized = fn (array $context) => app(MailboxSocketGrant::class)->resolve($context['grant'], $context['socket'], $context['channel']) !== null;
        $this->assertFalse($authorized($first));
        $this->assertCount(16, array_filter($contexts, $authorized));
        $this->assertTrue($authorized($contexts[23]));

        $owner->forceFill(['password' => 'replacement-password'])->save();
        $this->assertFalse($authorized($contexts[23]));
        $this->withSession(['password_hash_web' => $owner->getAuthPassword()]);
        app('session')->driver()->save();
        $replacement = $this->grant($mailbox->id, $contexts[23]['socket']);
        $this->assertNotSame($contexts[23]['grant'], $replacement['grant']);
        $this->assertTrue($authorized($replacement));
        $this->assertSame(1, $records());
        $this->post('/admin/logout')->assertRedirect();
        $this->assertFalse($authorized($replacement));
        $this->assertSame(0, $records());
    }

    public function test_existing_subscription_can_finish_on_its_original_grant_until_expiry(): void
    {
        $mailbox = Email::create(['email' => 'guest@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $current = $this->grant($mailbox->id);
        [$group] = explode('.', $current['grant'], 2);
        $cache = Cache::store(config('cache.limiter'));
        $identity = $cache->get(MailboxSocketGrant::CACHE_PREFIX.'session:'.$group)['identity'];
        $legacy = [...$current, 'grant' => bin2hex(random_bytes(32))];
        $key = MailboxSocketGrant::CACHE_PREFIX.'grant:'.hash('sha256', $legacy['grant']);
        $cache->put($key, $identity + ['socket' => $legacy['socket'], 'expiresAt' => now()->addMinute()->toISOString()], 60);

        $this->assertSame(['authorized' => true], $this->read($legacy, command: 'mailbox.authorize'));
        $this->travel(61)->seconds();
        $this->assertSame(['code' => 'access_revoked'], $this->read($legacy, command: 'mailbox.authorize'));
        $this->assertSame(['authorized' => true], $this->read($current, command: 'mailbox.authorize'));
    }

    public function test_waiting_for_a_revocation_releases_the_channel_at_the_grant_deadline(): void
    {
        config(['session.lifetime' => 1]);
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'deadline@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $channel = 'private-mailbox.'.$mailbox->id;
        [$wire, $connection] = $this->subscribe($app, $channel);
        $this->travel(59)->seconds();
        $mailbox->update(['closed_at' => now()]);
        EventDispatcher::dispatchSynchronously($app, ['event' => 'message.received', 'channel' => $channel, 'data' => '{"id":9}']);
        $this->assertFalse(app(ChannelManager::class)->for($app)->find($channel)->subscribed($connection));
        Loop::run();
        $this->assertNull(app(ChannelManager::class)->for($app)->find($channel));
        $this->assertSame([], $wire->frames);
    }

    public function test_delayed_delivery_checks_observe_the_membership_and_deadline_at_send_time(): void
    {
        config(['session.lifetime' => 1]);
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'pending@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $channel = 'private-mailbox.'.$mailbox->id;
        [$wire, $connection, $subscription] = $this->subscribe($app, $channel);
        $pending = [];
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            function (array $job, Closure $complete) use (&$pending) {
                $pending[] = $complete;
            }
        );
        $deliver = fn () => EventDispatcher::dispatchSynchronously($app, [
            'event' => 'message.received', 'channel' => $channel, 'data' => '{"id":9}',
        ]);
        $deliver();
        $server = app(Server::class);
        $server->message($connection, json_encode(['event' => 'pusher:unsubscribe', 'data' => ['channel' => $channel]]));
        $server->message($connection, $subscription);
        $this->assertCount(2, $pending);
        $pending[0](['authorized' => true]);
        $this->assertSame([], $wire->frames);
        $pending[1](['authorized' => true]);
        $this->assertSame('pusher_internal:subscription_succeeded', end($wire->frames)['event']);
        $wire->frames = [];
        $deliver();
        $this->travel(61)->seconds();
        $pending[2](['authorized' => true]);
        $this->assertSame([], $wire->frames);
        $this->assertNull(app(ChannelManager::class)->for($app)->find($channel));
    }

    public static function workerFailures(): array
    {
        return [['unavailable', 0], ['timeout', 0], ['busy', 6]];
    }

    #[DataProvider('workerFailures')]
    public function test_worker_failure_during_delivery_disconnects_for_a_fresh_snapshot(string $reason, int $retries): void
    {
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'unavailable@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $channel = 'private-mailbox.'.$mailbox->id;
        [$wire] = $this->subscribe($app, $channel);
        Log::spy();
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->times($retries + 1)->andReturnUsing(
            fn (array $job, Closure $complete) => $complete(['code' => $reason])
        );
        EventDispatcher::dispatchSynchronously($app, ['event' => 'message.received', 'channel' => $channel, 'data' => '{"id":9}']);
        Loop::run();
        $this->assertTrue($wire->closed);
        $this->assertSame([], $wire->frames);
        $this->assertNull(app(ChannelManager::class)->for($app)->find($channel));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'WebSocket') && $context === ['reason' => $reason, 'retries' => $retries]
        );
    }

    public function test_delivery_burst_reaches_all_sixteen_confirmed_tabs_after_backpressure(): void
    {
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'burst@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $channel = 'private-mailbox.'.$mailbox->id;
        $tabs = [];
        for ($i = 0; $i < 16; $i++) {
            $tabs[] = $this->subscribe($app, $channel);
        }
        $queued = $busy = $completed = 0;
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            function (array $job, Closure $complete) use (&$queued, &$busy, &$completed) {
                if ($queued >= 12) {
                    $busy++;
                    $complete(['code' => 'busy']);

                    return;
                }
                $queued++;
                Loop::futureTick(function () use ($job, $complete, &$queued, &$completed) {
                    $queued--;
                    $completed++;
                    $complete(app(MailboxSocketRead::class)->handle($job));
                });
            }
        );
        for ($id = 1; $id <= 30; $id++) {
            EventDispatcher::dispatchSynchronously($app, [
                'event' => 'message.received', 'channel' => $channel, 'data' => json_encode(['id' => $id]),
            ]);
        }
        Loop::run();
        $this->assertGreaterThan(0, $busy);
        $this->assertLessThanOrEqual(32, $completed);
        foreach ($tabs as [$wire, $connection]) {
            $this->assertFalse($wire->closed);
            $this->assertTrue(app(ChannelManager::class)->for($app)->find($channel)->subscribed($connection));
            $last = end($wire->frames);
            $this->assertSame('message.received', $last['event']);
            $this->assertSame(30, json_decode($last['data'], true)['id']);
        }
    }

    public function test_an_in_flight_delivery_check_keeps_the_independent_revocation_and_suppresses_later_mail(): void
    {
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'revocation@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $channel = 'private-mailbox.'.$mailbox->id;
        [$wire] = $this->subscribe($app, $channel);
        $checks = [];
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            function (array $job, Closure $complete) use (&$checks) {
                $checks[] = $complete;
            }
        );
        foreach (['message.received', 'mailbox.access-revoked', 'mailbox.access-revoked', 'message.received'] as $event) {
            EventDispatcher::dispatchSynchronously($app, [
                'event' => $event, 'channel' => $channel, 'data' => $event === 'message.received' ? '{"id":9}' : '[]',
            ]);
        }
        $this->assertCount(1, $checks);
        $this->assertSame([], $wire->frames);
        $checks[0](['code' => 'access_revoked']);
        $this->assertSame([['event' => 'mailbox.access-revoked', 'channel' => $channel, 'data' => '[]']], $wire->frames);
        $this->assertNull(app(ChannelManager::class)->for($app)->find($channel));
    }

    public function test_an_access_change_arriving_during_a_successful_delivery_check_requires_a_fresh_check(): void
    {
        $app = $this->startReverb();
        $mailbox = Email::create(['email' => 'race@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $channel = 'private-mailbox.'.$mailbox->id;
        [$wire] = $this->subscribe($app, $channel);
        $checks = [];
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            function (array $job, Closure $complete) use (&$checks) {
                $checks[] = $complete;
            }
        );
        EventDispatcher::dispatchSynchronously($app, ['event' => 'message.received', 'channel' => $channel, 'data' => '{"id":9}']);
        EventDispatcher::dispatchSynchronously($app, ['event' => 'mailbox.access-revoked', 'channel' => $channel, 'data' => '[]']);
        $checks[0](['authorized' => true]);
        $this->assertSame([], $wire->frames);
        $this->assertCount(2, $checks);
        $checks[1](['code' => 'access_revoked']);
        $this->assertSame(['mailbox.access-revoked'], array_column($wire->frames, 'event'));
    }

    public static function readCommands(): array
    {
        return [['mailbox.check', ['reason' => 'auto']], ['mailbox.page', ['continuation' => 'saved-page']]];
    }

    #[DataProvider('readCommands')]
    public function test_read_replies_wait_for_the_current_subscriptions_access_change(string $command, array $fields): void
    {
        $owner = User::factory()->create();
        $mailbox = Email::create(['email' => 'pending-read@test.invalid', 'owner_user_id' => $owner->id]);
        $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $checkingChange = false;
        $reads = $checks = [];
        $this->mock(MailboxSocketPool::class)->shouldReceive('submit')->andReturnUsing(
            function (array $job, Closure $complete) use (&$checkingChange, &$reads, &$checks) {
                $socket = $job['socket'];
                if ($job['command'] === 'mailbox.authorize') {
                    $authorize = fn () => $complete(app(MailboxSocketRead::class)->handle($job));
                    if ($checkingChange) {
                        $checks[$socket] = $authorize;
                    } else {
                        $authorize();
                    }
                } else {
                    $reads[$socket] = fn () => $complete(['page' => ['data' => [['subject' => 'Private header']]]]);
                }
            }
        );
        Factory::makePusherRouter('');
        $app = new Application('mailmm', 'test', 'secret', 60, 30, ['*'], 10000);
        $channel = 'private-mailbox.'.$mailbox->id;
        $request = fn ($connection, $id) => app(Server::class)->message($connection, json_encode([
            'event' => $command, 'channel' => $channel, 'data' => ['requestId' => $id] + $fields,
        ]));
        [$oldWire, $old] = $this->subscribe($app, $channel);
        $request($old, 'old-read');
        $owner->forceFill(['password' => 'replacement-password'])->save();
        app('session')->driver()->migrate();
        app('session')->driver()->forget('password_hash_web');
        $this->withCookie(config('session.cookie'), app('session')->getId());
        [$newWire, $new] = $this->subscribe($app, $channel);
        $request($new, 'new-read');
        $checkingChange = true;
        $change = fn () => EventDispatcher::dispatchSynchronously($app, ['event' => 'mailbox.access-revoked', 'channel' => $channel, 'data' => '[]']);
        $change();
        $reads[$old->id()]();
        $reads[$new->id()]();
        $this->assertSame([], $oldWire->frames);
        $this->assertSame([], $newWire->frames);
        $change();
        $checks[$old->id()]();
        $checks[$new->id()]();
        $this->assertSame(['mailbox.access-revoked'], array_column($oldWire->frames, 'event'));
        $this->assertSame([], $newWire->frames);
        $checks[$new->id()]();
        $this->assertSame(['mailbox.checked'], array_column($newWire->frames, 'event'));
        $this->assertSame('Private header', $newWire->frames[0]['data']['page']['data'][0]['subject']);
        $this->assertSame('new-read', $newWire->frames[0]['data']['requestId']);
    }

    public function test_requester_gets_http_equivalent_headers_and_bound_continuation_without_a_second_manual_charge(): void
    {
        $email = Email::create(['email' => 'reader@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $email->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        foreach (range(1, 33) as $i) {
            $email->messages()->create(['recipient' => $email->email, 'from' => 'sender', 'subject' => "Subject $i",
                'received_at' => now()->subSeconds($i), 'body_text' => 'private body']);
        }
        $context = $this->grant($email->id);
        $other = $this->grant($email->id, '789.012');
        $first = $this->read($context);
        $this->assertSame($this->getJson('/api/messages')->json(), $first['page']);
        $this->assertSame($this->getJson('/api/email')->headers->get('X-Inbox-Snapshot'), $first['snapshot']);
        $this->assertSame('rate_limited', $this->read($other)['code']);
        $this->assertSame('invalid_request', $this->read($other, ['continuation' => $first['continuation']], 'mailbox.page')['code']);
        $damaged = Crypt::encryptString(json_encode([
            'grant' => $context['grant'], 'expires' => now()->addMinute()->timestamp,
            'snapshot' => $first['snapshot'], 'cursor' => 'invalid',
        ], JSON_THROW_ON_ERROR));
        $this->assertSame('invalid_request', $this->read($context, ['continuation' => $damaged], 'mailbox.page')['code']);
        $next = $this->read($context, ['continuation' => $first['continuation']], 'mailbox.page');
        $this->assertSame('Subject 31', $next['page']['data'][0]['subject']);
        $this->assertNull($next['continuation']);
        $this->assertSame('invalid_request', $this->read($context, ['continuation' => $first['continuation']], 'mailbox.page')['code']);
        $this->travel(5)->seconds();
        $same = $this->read($context, ['snapshot' => $first['snapshot']]);
        $this->assertTrue($same['unchanged']);
        $this->assertArrayNotHasKey('page', $same);
        $this->travel(5)->seconds();
        $continuation = $this->read($context)['continuation'];
        $this->travel(61)->seconds();
        $this->assertSame('invalid_request', $this->read($context, ['continuation' => $continuation], 'mailbox.page')['code']);
    }

    public function test_open_inbox_reads_only_new_publications_over_http_and_socket(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['is_admin' => true]);
        $email = Email::create(['email' => 'long-inbox@test.invalid', 'expires_at' => now()->addDay()]);
        $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id, 'email_id' => $email->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $fields = ['recipient' => $email->email, 'from' => 'sender'];
        $pending = $email->messages()->create($fields + ['is_pending' => true, 'received_at' => now()->subDays(10)]);
        foreach (range(1, 300) as $days) {
            $email->messages()->create($fields + ['received_at' => now()->subDays($days)]);
        }
        $context = $this->grant($email->id);
        $expiring = $email->messages()->create($fields + ['received_at' => now()->subDays(365)->addSecond()]);
        DB::enableQueryLog();
        $snapshot = $this->getJson('/api/email')->assertOk()->headers->get('X-Inbox-Snapshot');
        $this->assertTrue($this->read($context, ['snapshot' => $snapshot, 'delta' => true])['unchanged']);
        $snapshotQueries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertDoesNotMatchRegularExpression('/(?:count|min)\s*\(/i', implode("\n", $snapshotQueries));

        $this->travel(2)->seconds();
        $this->artisan('mail:prune')->assertSuccessful();
        $this->assertDatabaseMissing('messages', ['id' => $expiring->id]);
        $late = $email->messages()->create($fields + ['received_at' => now()->subDays(150)]);
        $pending->update(['is_pending' => false]);
        $change = $this->read($context, ['snapshot' => $snapshot, 'delta' => true]);
        $this->assertTrue($change['page']['meta']['delta'] ?? false);
        $this->assertEqualsCanonicalizing([$pending->id, $late->id], array_column($change['page']['data'], 'id'));
        $this->assertNull($change['continuation']);
        $this->getJson('/api/messages', ['X-Inbox-Since' => $snapshot])
            ->assertOk()->assertHeader('X-Inbox-Snapshot', $change['snapshot'])
            ->assertExactJson($change['page']);

        $late->delete();
        $this->getJson('/api/messages', ['X-Inbox-Since' => $change['snapshot']])
            ->assertOk()->assertJsonCount(30, 'data')->assertJsonMissingPath('meta.delta');
    }

    public function test_a_burst_of_publications_uses_delta_continuations_even_as_more_arrive(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $email = Email::create(['email' => 'burst-inbox@test.invalid', 'expires_at' => now()->addDay()]);
        $this->actingAs($owner)->withSession([Auth::guard()->getName() => $owner->id, 'email_id' => $email->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $context = $this->grant($email->id);
        $snapshot = $this->getJson('/api/email')->headers->get('X-Inbox-Snapshot');
        $fields = ['recipient' => $email->email, 'from' => 'sender'];
        $published = [];
        foreach (range(1, 32) as $days) {
            $published[] = $email->messages()->create($fields + ['received_at' => now()->subDays($days)])->id;
        }
        $first = $this->read($context, ['snapshot' => $snapshot, 'delta' => true]);
        $this->assertTrue($first['page']['meta']['delta'] ?? false);
        $this->assertSame(array_slice($published, 0, 30), array_column($first['page']['data'], 'id'));
        $this->getJson('/api/messages', ['X-Inbox-Since' => $snapshot])->assertExactJson($first['page']);

        $email->messages()->create($fields + ['received_at' => now()->subDays(30)]);
        $next = $this->read($context, ['continuation' => $first['continuation']], 'mailbox.page');
        $this->assertSame($first['snapshot'], $next['snapshot']);
        $this->assertSame(array_slice($published, 30), array_column($next['page']['data'], 'id'));
        $this->assertNull($next['continuation']);
        $this->getJson('/api/messages?cursor='.urlencode($first['page']['meta']['next_cursor']), [
            'X-Inbox-Since' => $snapshot, 'X-Inbox-Until' => $first['snapshot'],
        ])->assertOk()->assertHeader('X-Inbox-Snapshot', $first['snapshot'])->assertExactJson($next['page']);
    }

    public function test_a_stale_session_socket_or_closed_address_cannot_read_private_headers(): void
    {
        $email = Email::create(['email' => 'reader@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $email->id]);
        $context = $this->grant($email->id);
        $this->assertSame('access_revoked', $this->read([...$context, 'socket' => '777.888'])['code']);
        $email->update(['closed_at' => now()]);
        $this->assertSame('access_revoked', $this->read($context)['code']);
        $email->update(['closed_at' => null]);
        $session = app('session')->driver();
        $session->getHandler()->destroy($session->getId());
        $this->assertSame('access_revoked', $this->read($context)['code']);
    }

    public function test_admin_exemption_uses_current_credentials_and_role(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $email = Email::create(['email' => 'admin-guest@test.invalid', 'expires_at' => now()->addDay()]);
        $this->actingAs($admin)->withSession([Auth::guard()->getName() => $admin->id, 'email_id' => $email->id]);
        $context = $this->grant($email->id);
        $this->assertFalse($this->read($context)['unchanged']);
        $this->assertFalse($this->read($context)['unchanged']);
        $admin->forceFill(['password' => 'new-secret'])->save();
        $this->assertSame('access_revoked', $this->read($context)['code']);
    }

    public function test_cookie_session_revocation_rejects_a_previously_issued_grant(): void
    {
        config(['session.driver' => 'cookie']);
        $email = Email::create(['email' => 'cookie@test.invalid', 'expires_at' => now()->addDay()]);
        app('session')->driver()->setRequestOnHandler(request());
        $this->withSession(['email_id' => $email->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $context = $this->grant($email->id);
        $this->assertFalse($this->read($context)['unchanged']);
        MailboxSocketGrant::revokeSession(request());
        $this->assertSame('access_revoked', $this->read($context)['code']);
    }

    public function test_ws_reads_do_not_revive_an_idle_database_session(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 20]);
        $email = Email::create(['email' => 'idle@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $email->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $context = $this->grant($email->id);
        $this->assertFalse($this->read($context)['unchanged']);
        $expired = now()->subMinutes(21)->timestamp;
        DB::table('sessions')->where('id', app('session')->getId())->update(['last_activity' => $expired]);
        $this->assertSame('access_revoked', $this->read($context)['code']);
        $this->assertSame($expired, DB::table('sessions')->where('id', app('session')->getId())->value('last_activity'));
    }

    public function test_address_closed_while_a_page_is_being_read_suppresses_its_private_headers(): void
    {
        $email = Email::create(['email' => 'concurrent@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $email->id]);
        $context = $this->grant($email->id);
        $email->messages()->create(['recipient' => $email->email, 'from' => 'sender', 'subject' => 'private',
            'received_at' => now()]);
        DB::listen(function ($query) use ($email) {
            if (str_contains($query->sql, 'AS has_attachments')) {
                $email->update(['closed_at' => now()]);
            }
        });
        $reply = $this->read($context);
        $this->assertSame('access_revoked', $reply['code'] ?? null);
        $this->assertArrayNotHasKey('page', $reply);
    }

    public function test_reverb_routes_commands_without_fanout_and_drops_results_after_unsubscribe(): void
    {
        $mailbox = Email::create(['email' => 'socket@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $app = new Application('mailmm', 'test', 'secret', 60, 30, ['*'], 10000);
        $channel = 'private-mailbox.'.$mailbox->id;
        $pending = [];
        $pool = $this->mock(MailboxSocketPool::class);
        $pool->shouldReceive('submit')->andReturnUsing(function (array $job, Closure $complete) use (&$pending) {
            if ($job['command'] === 'mailbox.authorize') {
                $complete(app(MailboxSocketRead::class)->handle($job));

                return;
            }
            $pending[] = fn () => $complete(app(MailboxSocketRead::class)->handle($job));
        });
        Factory::makePusherRouter('');
        $server = app(Server::class);
        foreach (range(0, 1) as $i) {
            $wire[$i] = $this->wire();
            $socket[$i] = new Connection($wire[$i], $app, 'http://localhost');
            $server->open($socket[$i]);
            $auth = $this->postJson('/api/broadcasting/auth', ['socket_id' => $socket[$i]->id(), 'channel_name' => $channel])->assertOk()->json();
            $subscription[$i] = json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, ...$auth]]);
            $server->message($socket[$i], $subscription[$i]);
            $this->assertSame('pusher_internal:subscription_succeeded', end($wire[$i]->frames)['event']);
            $wire[$i]->frames = [];
        }
        $send = fn ($i, $id, $reason = 'auto', $delivery = null) => $server->message($socket[$i], json_encode([
            'event' => 'mailbox.check', 'channel' => $channel, 'data' => ['requestId' => $id, 'reason' => $reason, 'deliveryId' => $delivery],
        ]));
        $send(0, 'invalid id');
        $this->assertSame([], $wire[0]->frames);
        $send(0, 'invalid-delivery', 'delivery', '55');
        $this->assertSame(['requestId' => 'invalid-delivery', 'code' => 'invalid_request'], end($wire[0]->frames)['data']);
        $this->assertSame([], $pending);
        $wire[0]->frames = [];
        $send(0, 'first');
        $send(1, 'parallel');
        $this->assertCount(2, $pending);
        $pending[1]();
        $this->assertSame([], $wire[0]->frames);
        $this->assertSame('mailbox.checked', end($wire[1]->frames)['event']);
        $pending[0]();
        $send(0, 'manual', 'manual');
        $pending[2]();
        $socket[0]->send(json_encode(['event' => 'message.received', 'channel' => $channel, 'data' => ['id' => 55]]));
        $send(0, 'delivered', 'auto', 55);
        $pending[3]();
        $this->assertSame('mailbox.checked', end($wire[0]->frames)['event']);
        $send(0, 'replay', 'auto', 55);
        $pending[4]();
        $this->assertSame('rate_limited', end($wire[0]->frames)['data']['code']);
        $send(0, 'replay');
        $this->assertSame('invalid_request', end($wire[0]->frames)['data']['code']);
        $send(0, 'stale', 'manual');
        $server->message($socket[0], json_encode(['event' => 'pusher:unsubscribe', 'data' => ['channel' => $channel]]));
        $before = $wire[0]->frames;
        $pending[5]();
        $this->assertSame($before, $wire[0]->frames);
        $server->message($socket[0], $subscription[0]);
        $send(0, 'same-authorization');
        $pending[6]();
        $this->assertSame('rate_limited', end($wire[0]->frames)['data']['code'] ?? null);
        $subject = str_repeat('🔔', 240);
        for ($i = 0; $i < 30; $i++) {
            $mailbox->messages()->create(['recipient' => $mailbox->email, 'from' => $subject, 'subject' => $subject,
                'received_at' => now()->subSeconds($i)]);
        }
        $this->travel(6)->seconds();
        $send(1, 'long', 'manual');
        $pending[7]();
        $chunks = array_values(array_filter($wire[1]->frames, fn ($frame) => $frame['event'] === 'mailbox.chunk'));
        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame(count($chunks), $chunks[0]['data']['total']);
        $large = json_decode(implode('', array_column(array_column($chunks, 'data'), 'content')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($subject, $large['page']['data'][29]['subject']);
        $this->assertSame('long', $large['requestId']);
    }

    public function test_extreme_headers_fit_the_socket_list_and_stay_complete_in_the_opened_message(): void
    {
        $email = Email::create(['email' => 'long@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $email->id]);
        $from = str_repeat('🔔', 200000);
        $letter = $email->messages()->create(['recipient' => $email->email, 'from' => $from, 'subject' => $from,
            'received_at' => now()]);
        $reply = $this->read($this->grant($email->id));
        $this->assertTrue(str_ends_with($reply['page']['data'][0]['subject'], '…'));
        $this->assertLessThan(32768, strlen(json_encode($reply, JSON_UNESCAPED_UNICODE)));
        $this->getJson('/api/messages/'.$letter->id)->assertOk()->assertJsonPath('data.subject', $from);
    }

    public function test_slow_worker_times_out_while_another_socket_completes_its_read(): void
    {
        config(['cache.limiter' => 'file']);
        $mailbox = Email::create(['email' => 'slow@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $mailbox->id]);
        $slow = $this->grant($mailbox->id, '1.2');
        [$group] = explode('.', $slow['grant'], 2);
        $key = MailboxSocketGrant::CACHE_PREFIX.'session:'.$group;
        $cache = Cache::store('file');
        $file = fopen($cache->getStore()->path($key), 'rb');
        $this->assertTrue(flock($file, LOCK_EX));
        $environment = $_ENV['CACHE_STORE'] ?? null;
        $_ENV['CACHE_STORE'] = 'file';
        try {
            $pool = new MailboxSocketPool;
            $answers = [];
            foreach (['slow' => $slow['grant'], 'fast' => 'missing.group'] as $id => $grant) {
                $job = [...$slow, 'grant' => $grant, 'command' => 'mailbox.check', 'data' => ['requestId' => $id, 'reason' => 'manual']];
                $pool->submit($job, function ($reply) use (&$answers, $id) {
                    $answers[$id] = $reply['code'];
                });
            }
            Loop::run();
            $this->assertSame(['fast' => 'access_revoked', 'slow' => 'timeout'], $answers);
        } finally {
            if ($environment === null) {
                unset($_ENV['CACHE_STORE']);
            } else {
                $_ENV['CACHE_STORE'] = $environment;
            }
            flock($file, LOCK_UN);
            fclose($file);
            $cache->forget($key);
        }
    }
}
