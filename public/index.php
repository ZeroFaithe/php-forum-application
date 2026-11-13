<?php

declare(strict_types=1);

/*
 * Front controller: the only public PHP entry point.
 *
 * Local run:  php -S localhost:8000 -t public public/index.php
 */

// Let PHP's built-in server serve real static files (CSS, JS, SVG) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

$view = require dirname(__DIR__) . '/config/bootstrap.php';

$router = new \Bramus\Router\Router();
(require dirname(__DIR__) . '/config/routes.php')($router, $view);
$router->run();
