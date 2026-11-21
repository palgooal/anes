<?php

/*
 * ANIS - front controller published to /home/latefcare/public_html/index.php by
 * deploy/cpanel-deploy.sh. Edit this template, never the published copy.
 *
 * Same steps as public/index.php, with absolute paths to the application in
 * /home/latefcare/anis, and public_path() set to the web root itself, so Vite
 * reads the manifest of the build that the browser is served
 * (public_html/build/manifest.json) and the "hot" file is looked for there.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = '/home/latefcare/anis/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require '/home/latefcare/anis/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once '/home/latefcare/anis/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
