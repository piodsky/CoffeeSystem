<?php
declare(strict_types=1);

return [
    'host'     => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', 3306),
    'name'     => (string) Env::get('DB_NAME', 'execomlogistics_db'),
    'user'     => (string) Env::get('DB_USER', 'root'),
    'password' => (string) Env::get('DB_PASS', ''),
    'charset'  => (string) Env::get('DB_CHARSET', 'utf8mb4'),
];
