<?php
return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => getenv('PHINX_ENV') ?: 'development',
        'development' => [
            'adapter' => getenv('DB_ADAPTER') ?: 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'name' => getenv('DB_NAME') ?: 'gdwb_dev',
            'user' => getenv('DB_USER') ?: 'gdwb',
            'pass' => getenv('DB_PASS') ?: 'gdwb',
            'port' => getenv('DB_PORT') ?: 5432,
            'charset' => 'utf8'
        ],
        'ci' => [
            'adapter' => getenv('CI_DB_ADAPTER') ?: 'pgsql',
            'host' => getenv('CI_DB_HOST') ?: '127.0.0.1',
            'name' => getenv('CI_DB_NAME') ?: 'gdwb_ci',
            'user' => getenv('CI_DB_USER') ?: 'ci',
            'pass' => getenv('CI_DB_PASS') ?: 'ci',
            'port' => getenv('CI_DB_PORT') ?: 5432,
            'charset' => 'utf8'
        ]
    ]
];
