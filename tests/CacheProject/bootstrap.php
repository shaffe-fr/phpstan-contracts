<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach (['CacheProject\\' => __DIR__ . '/src/', '' => __DIR__ . '/lib/'] as $prefix => $directory) {
        if ($prefix !== '' && !str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
