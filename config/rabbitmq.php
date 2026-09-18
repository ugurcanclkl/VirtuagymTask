<?php

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER'),
    'password' => env('RABBITMQ_PASSWORD'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'queue' => 'wallet-demo.deposits',
    'dead_letter_exchange' => 'wallet-demo.rejected',
    'dead_letter_queue' => 'wallet-demo.rejected',
];
