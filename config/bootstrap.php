<?php

declare(strict_types=1);

use App\Support\View;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// --- Database (Eloquent, which uses PDO prepared statements underneath) ----
$capsule = new Capsule();
$capsule->addConnection(require __DIR__ . '/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// --- Session ---------------------------------------------------------------
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('forum_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,   // Secure flag whenever the site is served over HTTPS
    'httponly' => true,       // not readable from JavaScript
    'samesite' => 'Lax',      // not sent on cross-site POSTs
]);
session_start();

// --- Base path (supports both `php -S` and an Apache sub-folder) -----------
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

return new View(dirname(__DIR__) . '/app/Views', $basePath);
