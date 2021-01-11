<?php

require_once __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$config = React\Dns\Config\Config::loadSystemConfigBlocking();

$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('127.0.0.1', $loop);

$domains = ['www.google.com', 'google.com', 'archlinux.net'];

$end = time() + 1;
while (time() < $end) {
    foreach ($domains as $domain) {
        $dns->resolve($domain)->then(
            function ($ip) use ($domain) {
                echo "[ OK] $domain => $ip" . PHP_EOL;
            },
            function () use ($domain) {
                echo "[ERR] $domain" . PHP_EOL;
            }
        );
    }

    shuffle($domains);
    $loop->run();
}
