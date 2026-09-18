<?php

$root = dirname(__DIR__);
foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        mkdir($root.'/'.$directory, 0775, true);
    }
}
if (! file_exists($root.'/.env')) {
    copy($root.'/.env.example', $root.'/.env');
}
if (! file_exists($root.'/database/wallet-demo.sqlite')) {
    touch($root.'/database/wallet-demo.sqlite');
}
echo "Local directories, environment file, and SQLite file are ready.\n";
