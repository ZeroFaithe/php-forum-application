<?php

declare(strict_types=1);

/*
 * Database settings. Values come from environment variables so that no
 * credentials are committed. The defaults match a local XAMPP install
 * (user "root", empty password). To override without environment variables,
 * copy config/database.local.example.php to config/database.local.php
 * (that file is git-ignored).
 */

$settings = [
    'driver'    => 'mysql',
    'host'      => getenv('DB_HOST') ?: '127.0.0.1',
    'port'      => getenv('DB_PORT') ?: '3306',
    'database'  => getenv('DB_NAME') ?: 'nested_forum',
    'username'  => getenv('DB_USER') ?: 'root',
    'password'  => getenv('DB_PASS') ?: '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    // Store and read all timestamps as UTC.
    'timezone'  => '+00:00',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $settings = array_merge($settings, require $local);
}

return $settings;
