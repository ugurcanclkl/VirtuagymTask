<?php

return [
    'default' => 'database',
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database',
            'connection' => null, // Same default database as users and wallets.
            'table' => 'jobs',
            'queue' => 'wallets',
            'retry_after' => 60,
            'after_commit' => false, // Queue insert is part of registration's transaction.
        ],
    ],
    'failed' => ['driver' => 'null'], // Do not persist raw exception traces in this demo.
];
