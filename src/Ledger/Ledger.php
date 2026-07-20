<?php

declare(strict_types=1);

namespace MeadBotApi\Ledger;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Tracks the prepaid Fireworks balance: usage (deducting, one row per /chat request, optionally
 * tagged with the caller's X-User-Id — see migrations/0001_create_chat_usage.sql and
 * 0003_add_user_id_to_chat_usage.sql) and deposits (adding, via POST /api/v1/balance/deposits —
 * see migrations/0002_create_balance_deposits.sql). The balance itself isn't a stored column;
 * it's always recomputed as SUM(deposits) - SUM(usage) so it can't drift out of sync.
 *
 * Usage recording is best-effort and never throws: /chat's actual functionality must not depend
 * on this database being reachable. Deposit recording and balance/usage reads DO throw when the
 * database isn't configured/reachable, since those endpoints have no reason to exist otherwise.
 */
final class Ledger
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** connect() - build a Ledger from the MYSQL_DB_* environment variables, or a disabled one (isConfigured() === false) if any are missing or the connection fails. */
    public static function connect(): self
    {
        $host = getenv('MYSQL_DB_HOST');
        $database = getenv('MYSQL_DB_DATABASE');
        $username = getenv('MYSQL_DB_USERNAME');
        $password = getenv('MYSQL_DB_PASSWORD');

        if ($host === false || $host === '' || $database === false || $username === false || $password === false) {
            return new self(null);
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            return new self(null);
        }

        return new self($pdo);
    }

    public function isConfigured(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * recordChatUsage(...) - best-effort insert of one /chat request's usage. Never throws —
     * silently does nothing if the database isn't configured, and swallows any insert failure —
     * since /chat's response has already been computed by the time this is called and shouldn't
     * fail just because the ledger couldn't be written. $userId is whatever the caller sent in
     * the X-User-Id header (null if omitted) — opaque to this class, just stored and grouped on.
     *
     * @param array{prompt_tokens?: int, cached_prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage
     */
    public function recordChatUsage(array $usage, float $costUsd, string $model, bool $success, ?string $errorMessage, ?string $userId): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_usage
                    (success, error_message, model, user_id, prompt_tokens, cached_prompt_tokens, completion_tokens, total_tokens, cost_usd)
                 VALUES
                    (:success, :error_message, :model, :user_id, :prompt_tokens, :cached_prompt_tokens, :completion_tokens, :total_tokens, :cost_usd)'
            );
            $stmt->execute([
                ':success' => $success ? 1 : 0,
                ':error_message' => $errorMessage,
                ':model' => $model,
                ':user_id' => $userId,
                ':prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                ':cached_prompt_tokens' => $usage['cached_prompt_tokens'] ?? 0,
                ':completion_tokens' => $usage['completion_tokens'] ?? 0,
                ':total_tokens' => $usage['total_tokens'] ?? 0,
                ':cost_usd' => $costUsd,
            ]);
        } catch (PDOException) {
            // Ledger is auxiliary/track-only — a write failure here must never surface as a
            // /chat failure.
        }
    }

    /** recordDeposit(amountUsd, note) - insert one balance deposit. Throws if the database isn't configured/reachable. */
    public function recordDeposit(float $amountUsd, ?string $note): void
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The balance database is not configured on this server.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO balance_deposits (amount_usd, note) VALUES (:amount_usd, :note)');
        $stmt->execute([':amount_usd' => $amountUsd, ':note' => $note]);
    }

    /**
     * getBalance() - the running balance: total deposits minus total usage cost, recomputed from
     * both tables. Throws if the database isn't configured/reachable.
     *
     * @return array{totalDepositsUsd: float, totalUsageUsd: float, balanceUsd: float}
     */
    public function getBalance(): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The balance database is not configured on this server.');
        }

        $totalDeposits = (float) $this->pdo->query('SELECT COALESCE(SUM(amount_usd), 0) FROM balance_deposits')->fetchColumn();
        $totalUsage = (float) $this->pdo->query('SELECT COALESCE(SUM(cost_usd), 0) FROM chat_usage')->fetchColumn();

        return [
            'totalDepositsUsd' => $totalDeposits,
            'totalUsageUsd' => $totalUsage,
            'balanceUsd' => $totalDeposits - $totalUsage,
        ];
    }

    /**
     * usageByUser() - per-user totals across every recorded /chat request (including failed
     * ones, which may have zero cost), ordered by total cost descending. A null userId groups
     * every request that omitted the X-User-Id header. Throws if the database isn't
     * configured/reachable.
     *
     * @return array<int, array{userId: ?string, requestCount: int, totalUsageUsd: float, totalTokens: int, lastUsedAt: string}>
     */
    public function usageByUser(): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The balance database is not configured on this server.');
        }

        $stmt = $this->pdo->query(
            'SELECT
                user_id,
                COUNT(*) AS request_count,
                COALESCE(SUM(cost_usd), 0) AS total_usage_usd,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                MAX(created_at) AS last_used_at
             FROM chat_usage
             GROUP BY user_id
             ORDER BY total_usage_usd DESC'
        );

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'userId' => $row['user_id'],
                'requestCount' => (int) $row['request_count'],
                'totalUsageUsd' => (float) $row['total_usage_usd'],
                'totalTokens' => (int) $row['total_tokens'],
                'lastUsedAt' => (string) $row['last_used_at'],
            ];
        }
        return $rows;
    }
}
