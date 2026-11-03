<?php

declare(strict_types=1);

/**
 * Test bootstrap that works in both places this plugin is run from.
 *
 * Unit tests that touch no Mautic class run anywhere, including a bare checkout
 * with no dependencies installed, because the plugin autoloads itself below.
 *
 * When the checkout sits inside a Mautic installation, that installation's
 * autoloader is picked up as well, so tests that do need Mautic classes work too.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MauticPlugin\\MauticMcpBundle\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = dirname(__DIR__).'/'.$relative.'.php';

    if (is_file($file)) {
        require_once $file;
    }
});

foreach ([
    dirname(__DIR__).'/vendor/autoload.php',          // standalone, with dev dependencies
    dirname(__DIR__, 3).'/vendor/autoload.php',       // plugins/<this>/ inside Mautic
    dirname(__DIR__, 4).'/vendor/autoload.php',       // docroot/plugins/<this>/ layouts
] as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}
