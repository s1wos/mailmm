<?php

use App\Http\Controllers\PanelController;
use App\Http\Controllers\PanelDomainController;
use App\Http\Controllers\PanelErrorLogController;
use App\Http\Controllers\PanelLoginController;
use App\Http\Controllers\PanelSettingsController;
use App\Http\Middleware\EnsurePanelUser;
use App\Http\Middleware\LimitRepeatedAction;
use App\Http\Middleware\RedirectAuthenticatedPanelUser;
use App\Http\Middleware\ThrottleOrdinaryUser;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/about', 'app');
Route::view('/help', 'app');

Route::prefix('api')->middleware('cache.headers:private;no_store')->group(__DIR__.'/api.php');
Route::prefix('admin')->group(function () {
    Route::get('/session', [PanelLoginController::class, 'session']);
    Route::get('/login', [PanelLoginController::class, 'show'])->middleware(RedirectAuthenticatedPanelUser::class)->name('login');
    Route::post('/login', [PanelLoginController::class, 'login'])
        ->middleware([
            ThrottleOrdinaryUser::with(5, 1, ThrottleOrdinaryUser::PREFIXES['login']),
            LimitRepeatedAction::class.':login',
            RedirectAuthenticatedPanelUser::class,
        ]);

    Route::middleware(['auth', EnsurePanelUser::class])->group(function () {
        Route::post('/logout', [PanelLoginController::class, 'logout'])->middleware(LimitRepeatedAction::class.':logout')->name('panel.logout');
        Route::put('/account/password', [PanelSettingsController::class, 'mailboxPassword'])
            ->middleware([ThrottleOrdinaryUser::with(5, 1, ThrottleOrdinaryUser::PREFIXES['password']), 'can:change-mailbox-password'])
            ->name('panel.account.password');
        Route::get('/', [PanelController::class, 'index'])->middleware(LimitRepeatedAction::class.':panel')->name('panel.index');
        Route::get('/messages/{message}', [PanelController::class, 'show'])->whereNumber('message')->middleware(LimitRepeatedAction::class.':panel')->name('panel.message');
        Route::get('/messages/{message}/attachments/{index}', [PanelController::class, 'attachment'])
            ->whereNumber(['message', 'index'])->name('panel.attachment');

        Route::middleware('can:administer-mail')->group(function () {
            Route::get('/active-users', [PanelController::class, 'activeUsers'])->name('panel.active-users');
            Route::get('/errors', PanelErrorLogController::class)->name('panel.errors');
            Route::get('/archive', [PanelController::class, 'archive'])->name('panel.archive');
            Route::get('/temporary-mailboxes', [PanelController::class, 'temporaryMailboxes'])->name('panel.temporary-mailboxes');
            Route::get('/mailboxes', [PanelController::class, 'mailboxes'])->name('panel.mailboxes');
            Route::post('/mailboxes', [PanelController::class, 'createMailbox'])->name('panel.mailboxes.create');
            Route::post('/mailboxes/{mailbox}/password', [PanelController::class, 'resetMailboxPassword'])
                ->whereNumber('mailbox')->name('panel.mailboxes.password');
            Route::get('/settings', [PanelSettingsController::class, 'show'])->name('panel.settings');
            Route::post('/settings/domains', [PanelDomainController::class, 'store'])->name('panel.domains.store');
            Route::patch('/settings/domains/{domain}', [PanelDomainController::class, 'update'])
                ->whereNumber('domain')->name('panel.domains.update');
            Route::patch('/settings/issuance', [PanelSettingsController::class, 'issuance'])->name('panel.settings.issuance');
            Route::put('/settings/password', [PanelSettingsController::class, 'password'])->name('panel.settings.password');
        });
    });
});
