<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '5432');
            $db   = Env::get('DB_DATABASE', 'supportassist');
            $user = Env::get('DB_USERNAME', 'postgres');
            $pass = Env::get('DB_PASSWORD', 'postgres');

            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
                ]);
            } catch (PDOException $e) {
                // Never leak DSN/credentials to the client.
                error_log('DB connection failed: ' . $e->getMessage());
                Response::error('Database connection failed', 500);
                exit;
            }
        }

        return self::$instance;
    }
}
