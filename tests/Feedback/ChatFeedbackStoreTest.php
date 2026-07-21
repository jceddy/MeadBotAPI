<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Feedback;

use MeadBotApi\Feedback\ChatFeedbackStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises ChatFeedbackStore's query logic against an in-memory SQLite database rather than a
 * real MySQL server (this project has no MySQL available in CI/dev) -- same approach as
 * Ledger\LedgerTest.
 */
final class ChatFeedbackStoreTest extends TestCase
{
    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE chat_feedback (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                discord_user_id TEXT NOT NULL,
                discord_message_id TEXT NOT NULL,
                discord_channel_id TEXT NULL,
                discord_guild_id TEXT NULL,
                messages TEXT NOT NULL
            )'
        );
        return $pdo;
    }

    public function testIsConfiguredReflectsWhetherAPdoWasGiven(): void
    {
        self::assertTrue((new ChatFeedbackStore($this->sqlitePdo()))->isConfigured());
        self::assertFalse((new ChatFeedbackStore(null))->isConfigured());
    }

    public function testRecordInsertsARowWithMessagesEncodedAsJson(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new ChatFeedbackStore($pdo);

        $messages = [
            ['role' => 'user', 'content' => 'How much honey for 5 gallons at 1.100?'],
            ['role' => 'assistant', 'content' => "You'll need about 15 lbs of honey."],
        ];
        $store->record('user-1', 'msg-1', 'channel-1', 'guild-1', $messages);

        $row = $pdo->query('SELECT * FROM chat_feedback')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('user-1', $row['discord_user_id']);
        self::assertSame('msg-1', $row['discord_message_id']);
        self::assertSame('channel-1', $row['discord_channel_id']);
        self::assertSame('guild-1', $row['discord_guild_id']);
        self::assertSame($messages, json_decode($row['messages'], true));
    }

    public function testRecordAllowsNullChannelAndGuildIds(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new ChatFeedbackStore($pdo);

        $store->record('user-1', 'msg-1', null, null, [['role' => 'user', 'content' => 'hi']]);

        $row = $pdo->query('SELECT * FROM chat_feedback')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['discord_channel_id']);
        self::assertNull($row['discord_guild_id']);
    }

    public function testRecordThrowsWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChatFeedbackStore(null))->record('user-1', 'msg-1', null, null, [['role' => 'user', 'content' => 'hi']]);
    }
}
