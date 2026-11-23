<?php

use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Middleware\AddPanelUrl;
use App\Http\Middleware\LimitRepeatedAction;
use App\Http\Middleware\ThrottleOrdinaryUser;
use Illuminate\Support\Facades\Route;

Route::get('/email', [EmailController::class, 'show'])->middleware(AddPanelUrl::class);
Route::get('/email/options', [EmailController::class, 'options'])->middleware(LimitRepeatedAction::class.':options');
Route::post('/emails', [EmailController::class, 'store'])->middleware(AddPanelUrl::class);
Route::delete('/email', [EmailController::class, 'destroy'])
    ->middleware(ThrottleOrdinaryUser::with(10, 60, ThrottleOrdinaryUser::PREFIXES['close']));
Route::get('/messages', [MessageController::class, 'index'])->middleware(LimitRepeatedAction::class.':page');
Route::get('/messages/{message}', [MessageController::class, 'show'])->whereNumber('message')->middleware(LimitRepeatedAction::class.':letter');
Route::get('/messages/{message}/attachments/{index}', [MessageController::class, 'attachment'])
    ->whereNumber(['message', 'index'])->name('mailbox.attachment');

require __DIR__.'/channels.php';
