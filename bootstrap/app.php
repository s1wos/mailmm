<?php

use App\Http\Controllers\MailImageController;
use App\Http\Middleware\OmitUnchangedSessionCookies;
use App\Http\Middleware\PanelHeaders;
use App\Http\Middleware\ReadMailImageSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function () {
            Route::get('/mail-images/{message}/{token}', MailImageController::class)
                ->whereNumber('message')->where('token', '[A-Za-z0-9_-]+')
                ->middleware([EncryptCookies::class, ReadMailImageSession::class, AuthenticateSession::class, 'throttle:240,1,mail-images:']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PanelHeaders::class);
        $middleware->web(append: OmitUnchangedSessionCookies::class);
        $middleware->appendToPriorityList(EncryptCookies::class, OmitUnchangedSessionCookies::class);
        $middleware->authenticateSessions();
    })
    ->withExceptions()
    ->create();
