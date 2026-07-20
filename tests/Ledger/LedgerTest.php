<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Ledger;

use MeadBotApi\Ledger\Ledger;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises Ledger's query logic against an in-memory SQLite database rather than a real MySQL
 * server (this project has no MySQL available in CI/dev). The schema below is a SQLite-typed
 * stand-in for migrations/000*.sql — same columns/names, loosened types — used only here; it is
 * not what ships to production.
 */
final class LedgerTest extends TestCase
{
    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE chat_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                success INTEGER NOT NULL,
                error_message TEXT NULL,
                model TEXT NOT NULL,
                user_id TEXT NULL,
                prompt_tokens INTEGER NOT NULL DEFAULT 0,
                cached_prompt_tokens INTEGER NOT NULL DEFAULT 0,
                completion_tokens INTEGER NOT NULL DEFAULT 0,
                total_tokens INTEGER NOT NULL DEFAULT 0,
                cost_usd REAL NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE balance_deposits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                amount_usd REAL NOT NULL,
                note TEXT NULL
            )'
        );
        return $pdo;
    }

    public function testIsConfiguredReflectsWhetherAPdoWasGiven(): void
    {
        self::assertTrue((new Ledger($this->sqlitePdo()))->isConfigured());
        self::assertFalse((new Ledger(null))->isConfigured());
    }

    public function testRecordChatUsageInsertsARow(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordChatUsage(
            ['prompt_tokens' => 100, 'cached_prompt_tokens' => 60, 'completion_tokens' => 20, 'total_tokens' => 120],
            0.0234,
            'accounts/fireworks/models/gpt-oss-120b',
            true,
            null,
            'alice'
        );

        $row = $pdo->query('SELECT * FROM chat_usage')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, $row['success']);
        self::assertNull($row['error_message']);
        self::assertSame('accounts/fireworks/models/gpt-oss-120b', $row['model']);
        self::assertSame('alice', $row['user_id']);
        self::assertSame(100, $row['prompt_tokens']);
        self::assertSame(60, $row['cached_prompt_tokens']);
        self::assertEqualsWithDelta(0.0234, $row['cost_usd'], 1e-9);
    }

    public function testRecordChatUsageWithNoUserIdStoresNull(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordChatUsage(['prompt_tokens' => 10], 0.001, 'model', true, null, null);

        $row = $pdo->query('SELECT * FROM chat_usage')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['user_id']);
    }

    public function testRecordChatUsageOnAFailureStoresTheErrorMessage(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordChatUsage(
            ['prompt_tokens' => 0, 'cached_prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            0.0,
            'accounts/fireworks/models/gpt-oss-120b',
            false,
            'Fireworks request failed (HTTP 500): internal error',
            'bob'
        );

        $row = $pdo->query('SELECT * FROM chat_usage')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, $row['success']);
        self::assertSame('Fireworks request failed (HTTP 500): internal error', $row['error_message']);
        self::assertSame('bob', $row['user_id']);
    }

    public function testRecordChatUsageIsANoOpWhenNotConfiguredRatherThanThrowing(): void
    {
        $ledger = new Ledger(null);
        $ledger->recordChatUsage(['prompt_tokens' => 1], 0.01, 'model', true, null, 'alice');
        // No exception, nothing to assert beyond "it didn't throw" — /chat's response must never
        // depend on the ledger being reachable.
        self::assertTrue(true);
    }

    public function testRecordDepositThrowsWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        (new Ledger(null))->recordDeposit(50.0, 'top-up');
    }

    public function testGetBalanceThrowsWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        (new Ledger(null))->getBalance();
    }

    public function testGetBalanceIsDepositsMinusUsage(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordDeposit(50.0, 'first top-up');
        $ledger->recordDeposit(25.0, 'second top-up');
        $ledger->recordChatUsage(['prompt_tokens' => 1000], 0.75, 'model', true, null, null);
        $ledger->recordChatUsage(['prompt_tokens' => 1000], 1.25, 'model', true, null, null);

        $balance = $ledger->getBalance();

        self::assertEqualsWithDelta(75.0, $balance['totalDepositsUsd'], 1e-9);
        self::assertEqualsWithDelta(2.0, $balance['totalUsageUsd'], 1e-9);
        self::assertEqualsWithDelta(73.0, $balance['balanceUsd'], 1e-9);
    }

    public function testGetBalanceWithNoRowsYetIsZero(): void
    {
        $balance = (new Ledger($this->sqlitePdo()))->getBalance();

        self::assertSame(['totalDepositsUsd' => 0.0, 'totalUsageUsd' => 0.0, 'balanceUsd' => 0.0], $balance);
    }

    public function testNegativeDepositActsAsAManualCorrection(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordDeposit(100.0, 'top-up');
        $ledger->recordDeposit(-10.0, 'reconciliation correction');

        self::assertEqualsWithDelta(90.0, $ledger->getBalance()['totalDepositsUsd'], 1e-9);
    }

    public function testUsageByUserThrowsWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        (new Ledger(null))->usageByUser();
    }

    public function testUsageByUserIsEmptyWithNoRowsYet(): void
    {
        self::assertSame([], (new Ledger($this->sqlitePdo()))->usageByUser());
    }

    public function testUsageByUserGroupsAndOrdersByTotalCostDescending(): void
    {
        $pdo = $this->sqlitePdo();
        $ledger = new Ledger($pdo);

        $ledger->recordChatUsage(['prompt_tokens' => 100, 'total_tokens' => 100], 0.05, 'model', true, null, 'alice');
        $ledger->recordChatUsage(['prompt_tokens' => 200, 'total_tokens' => 200], 0.10, 'model', true, null, 'alice');
        $ledger->recordChatUsage(['prompt_tokens' => 50, 'total_tokens' => 50], 0.50, 'model', true, null, 'bob');
        // No X-User-Id header sent — groups under a null userId.
        $ledger->recordChatUsage(['prompt_tokens' => 10, 'total_tokens' => 10], 0.01, 'model', true, null, null);

        $rows = $ledger->usageByUser();

        self::assertCount(3, $rows);
        // bob spent the most overall, despite fewer requests.
        self::assertSame('bob', $rows[0]['userId']);
        self::assertSame(1, $rows[0]['requestCount']);
        self::assertEqualsWithDelta(0.50, $rows[0]['totalUsageUsd'], 1e-9);

        self::assertSame('alice', $rows[1]['userId']);
        self::assertSame(2, $rows[1]['requestCount']);
        self::assertEqualsWithDelta(0.15, $rows[1]['totalUsageUsd'], 1e-9);
        self::assertSame(300, $rows[1]['totalTokens']);

        self::assertNull($rows[2]['userId']);
        self::assertSame(1, $rows[2]['requestCount']);
        self::assertEqualsWithDelta(0.01, $rows[2]['totalUsageUsd'], 1e-9);
    }
}
