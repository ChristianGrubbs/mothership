<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Note Files PHP Dropped Before The Application Started
|--------------------------------------------------------------------------
|
| PHP silently drops every file past `max_file_uploads` and says so only in a
| request-startup warning, which later notices would overwrite. Recorded here,
| first, so an upload route refuses the request instead of storing the first
| files and reporting success (AssetHelper::droppedFileUploadsMessage()).
|
*/

if (str_contains((string) (error_get_last()['message'] ?? ''), 'Maximum number of allowable file uploads')) {
    $_SERVER['MOTHERSHIP_FILE_UPLOADS_EXCEEDED'] = '1';
}

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
*/

if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
    $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = $base === '/' || $base === '\\' ? '' : rtrim($base, '/');

    header('Location: '.$base.'/install.php');
    exit;
}

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
