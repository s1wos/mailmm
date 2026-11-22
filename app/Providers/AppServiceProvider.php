<?php

namespace App\Providers;

use App\Database\RollbackMigrator;
use App\Models\User;
use App\Services\MailboxChannelConnections;
use App\Services\MailboxChannelEventHandler;
use App\Services\MailboxSocketHandler;
use App\Services\MailboxSocketPool;
use Illuminate\Auth\Access\Response;
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
        Event::listen(MessageReceived::class, [MailboxSocketHandler::class, 'received']);
        Event::listen(MessageSent::class, [MailboxSocketHandler::class, 'sent']);
        Gate::define('administer-mail', fn (User $user): Response => $user->is_admin
            ? Response::allow()
            : Response::deny(''));
    }
}
