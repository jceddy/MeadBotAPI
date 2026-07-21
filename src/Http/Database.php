<?php

declare(strict_types=1);

namespace MeadBotApi\Http;

use PDO;
use PDOException;

/**
 * Builds a PDO connection to the shared MYSQL_DB_* database from environment variables --
 * factored out of Ledger\Ledger so Feedback\ChatFeedbackStore (and anything else needing the same
 * database) doesn't duplicate the DSN-building/connection logic. Returns null (rather than
 * throwing) if any required variable is missing or the connection fails; callers decide whether
 * that's fatal for their own use case. MYSQL_DB_PORT is optional -- omit it to use MySQL's
 * default port (3306).
 */
final class Database
{
    public static function connect(): ?PDO
    {
        $host = getenv('MYSQL_DB_HOST');
        $port = getenv('MYSQL_DB_PORT');
        $database = getenv('MYSQL_DB_DATABASE');
        $username = getenv('MYSQL_DB_USERNAME');
        $password = getenv('MYSQL_DB_PASSWORD');

        if ($host === false || $host === '' || $database === false || $username === false || $password === false) {
            return null;
        }

        $dsn = "mysql:host={$host}";
        if ($port !== false && $port !== '') {
            $dsn .= ";port={$port}";
        }
        $dsn .= ";dbname={$database};charset=utf8mb4";

        try {
            return new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException) {
            return null;
        }
    }
}
