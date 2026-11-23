<?php

use App\Services\SitePresence;
use Illuminate\Foundation\Vite;

$panel = request()->is('admin', 'admin/*');
$vite = app(Vite::class);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="mailmm-is-admin" content="<?= request()->user()?->is_admin ? '1' : '0' ?>">
    <meta name="reverb-key" content="<?= e(config('broadcasting.default') === 'reverb' ? config('broadcasting.connections.reverb.key') : '') ?>">
    <meta name="site-visitor-channel" content="<?= e(SitePresence::pageChannel(request())) ?>">
    <meta name="robots" content="<?= $panel ? 'noindex' : 'index,follow' ?>">
    <meta name="theme-color" content="<?= $panel ? '#e9ebfa' : '#e5e6f5' ?>">
    <title><?= $panel ? 'Панель' : 'Временная почта' ?> · mailmm</title>
    <link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/svg+xml" href="<?= e($vite->asset('resources/images/mailmm.svg')) ?>" sizes="any">
    <?= $vite(['resources/css/mailmm-theme.css', 'resources/js/app.ts']) ?>
    <link rel="stylesheet" id="public-css" href="<?= e($vite->asset('resources/css/app.css')) ?>" media="<?= $panel ? 'not all' : 'all' ?>">
    <link rel="stylesheet" id="panel-css" href="<?= e(asset('panel.css')) ?>" media="<?= $panel ? 'all' : 'not all' ?>">
</head>
<body>
    <div id="app"></div>
    <noscript>Включите JavaScript, чтобы открыть почту.</noscript>
</body>
</html>
