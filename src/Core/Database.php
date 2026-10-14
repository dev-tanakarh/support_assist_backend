<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        try {
            $appEnv = (string) Env::get('APP_ENV', 'local');
            $host = (string) Env::get('DB_HOST', $appEnv === 'production' ? '' : '127.0.0.1');
            $port = (string) Env::get('DB_PORT', '5432');
            $db = (string) Env::get('DB_DATABASE', 'supportassist');
            $user = (string) Env::get('DB_USERNAME', $appEnv === 'production' ? '' : 'postgres');
            $pass = (string) Env::get('DB_PASSWORD', $appEnv === 'production' ? '' : 'postgres');
            $sslMode = (string) Env::get('DB_SSLMODE', $appEnv === 'production' ? 'verify-full' : 'prefer');
            $sslRootCert = (string) Env::get('DB_SSLROOTCERT', '');

            if ($appEnv === 'production') {
                foreach (['DB_HOST' => $host, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass] as $key => $value) {
                    if ($value === '') {
                        throw new RuntimeException("Missing required environment variable: {$key}");
                    }
                }
                if ($sslMode !== 'verify-full' || $sslRootCert === '' || !is_readable($sslRootCert)) {
                    throw new RuntimeException(
                        'Production PostgreSQL connections require DB_SSLMODE=verify-full and a readable DB_SSLROOTCERT.'
                    );
                }
            }

            $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode={$sslMode}";
            if ($sslRootCert !== '') {
                $dsn .= ";sslrootcert={$sslRootCert}";
            }

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (PDOException | RuntimeException $e) {
            // Never send DSNs, credentials, or raw driver errors to API clients.
            error_log('DB connection failed: ' . $e->getMessage());
            Response::error('Database connection failed', 500);
        }

        return self::$instance;
    }
}

error_log('DB SSL diagnostics: mode=' . $sslMode);
error_log('DB SSL diagnostics: cert_path=' . $sslRootCert);
error_log('DB SSL diagnostics: cert_readable=' . (is_readable($sslRootCert) ? 'yes' : 'no'));
error_log('DB SSL diagnostics: cert_exists=' . (file_exists($sslRootCert) ? 'yes' : 'no'));