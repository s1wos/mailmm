<?php

use App\Http\Controllers\Api\MailboxChannelController;
use Illuminate\Support\Facades\Route;

Route::post('/broadcasting/auth', MailboxChannelController::class);
