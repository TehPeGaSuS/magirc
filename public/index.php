<?php

declare(strict_types=1);

// Under PHP's built-in server (php -S host:port -t public public/index.php)
// let it serve existing files such as the assets itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('MagIRC is not installed: run `composer install` in ' . $root);
}
require $root . '/vendor/autoload.php';

if (!is_file($root . '/conf/magirc.php')) {
    http_response_code(500);
    exit('MagIRC is not configured: copy conf/magirc.example.php to conf/magirc.php and run `php bin/magirc check`.');
}

$services = Magirc\Services::fromRoot($root);
date_default_timezone_set($services->config->string('display.timezone', 'UTC'));
Magirc\Web\App::create($services)->run();
