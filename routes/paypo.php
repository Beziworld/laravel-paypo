<?php

declare(strict_types=1);

use Beziworld\PayPo\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$middleware = Config::get('paypo.notifications.middleware', []);
$path = trim((string) Config::get('paypo.notifications.path', 'paypo/notification'), '/');

Route::post($path . '/{connection?}', NotificationController::class)
    ->middleware(is_array($middleware) ? $middleware : [])
    ->where('connection', '[A-Za-z0-9_.\-]+')
    ->name((string) Config::get('paypo.notifications.name', 'paypo.notification'));
