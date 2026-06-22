<?php
namespace GDWB;

class DB
{
    private static $pdo = null;

    public static function getPDO(): \PDO
    {
        if (self::$pdo === null) {
            $driver = getenv('DB_ADAPTER') ?: 'pgsql';
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432);
            $name = getenv('DB_NAME') ?: 'gdwb_dev';
            $user = getenv('DB_USER') ?: 'gdwb';
            $pass = getenv('DB_PASS') ?: 'gdwb';

            if ($driver === 'mysql') {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
            } else {
                $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
                $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
            }

            self::$pdo = new \PDO($dsn, $user, $pass, $options);
        }
        return self::$pdo;
    }
}
