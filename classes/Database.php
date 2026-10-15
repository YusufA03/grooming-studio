<?php
// Every class that touches dates loads this file, so the clock is always right.
require_once __DIR__ . '/../config/timezone.php';

// Creates one shared connection to the MySQL database.
class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = require __DIR__ . '/../config/db_config.php';

            // Only the automated test runner (tools/run_tests.php) sets these two variables, so that it
            // can use a separate test database. On the real site they do not exist and nothing changes.
            if (getenv('GS_TEST_MODE') === '1' && getenv('GS_DB_NAME') !== false && getenv('GS_DB_NAME') !== '') {
                $config['dbname'] = (string) getenv('GS_DB_NAME');
            }

            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

            self::$connection = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$connection;
    }
}
