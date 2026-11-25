<?php

namespace App\Providers;

use App\Console\Commands\WorkMailForwards;
use App\Database\RollbackMigrator;
use App\Models\User;
use App\Services\MailboxChannelConnections;
use App\Services\MailboxChannelEventHandler;
use App\Services\MailboxSocketHandler;
use App\Services\MailboxSocketPool;
use Illuminate\Auth\Access\Response;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChannelConnectionManager::class, MailboxChannelConnections::class);
        $this->app->bind(EventHandler::class, MailboxChannelEventHandler::class);
        $this->app->extend('migrator', fn ($migrator, $app) => new RollbackMigrator(
            $migrator->getRepository(), $app['db'], $app['files'], $app['events'],
        ));
        $this->app->singleton(MailboxSocketHandler::class);
        $this->app->singleton(MailboxSocketPool::class);
    }

    public function boot(): void
    {
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->exitCode === 0 && in_array($event->command, ['queue:restart', 'queue:resume', 'queue:continue'], true)) {
                $database = DB::connection(config('queue.connections.forwarding.connection'));
                if ($database->getDriverName() === 'pgsql') {
                    $database->statement('select pg_notify(?, ?)', [WorkMailForwards::CHANNEL, WorkMailForwards::CONTROL]);
                }
            }
        });
        Event::listen(MessageReceived::class, [MailboxSocketHandler::class, 'received']);
        Event::listen(MessageSent::class, [MailboxSocketHandler::class, 'sent']);
        Gate::define('administer-mail', fn (User $user): Response => $user->is_admin
            ? Response::allow()
            : Response::deny(''));
        Gate::define('change-mailbox-password', fn (User $user): Response => ! $user->is_admin && $user->mailbox()->activePermanent()->exists()
            ? Response::allow()
            : Response::deny(''));
    }
}
