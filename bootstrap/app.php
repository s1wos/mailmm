<?php

use App\Http\Middleware\OmitUnchangedSessionCookies;
use App\Http\Middleware\PanelHeaders;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PanelHeaders::class);
        $middleware->web(append: OmitUnchangedSessionCookies::class);
        $middleware->appendToPriorityList(EncryptCookies::class, OmitUnchangedSessionCookies::class);
        $middleware->authenticateSessions();
    })
    ->withExceptions()
    ->create();
