<?php

declare(strict_types=1);

namespace MeadBotApi\Feedback;

use MeadBotApi\Http\Database;
use PDO;
use RuntimeException;

/**
 * Persists negative feedback on a !chat reply -- a Discord 👎 reaction on one of MeadBot's chat
 * responses -- see migrations/0004_create_chat_feedback.sql. Recorded via
 * POST /api/v1/chat/feedback, called by MeadBot's messageReactionAdd handler once it's confirmed
 * the reacted-to message really was a !chat reply. Throws when the database isn't
 * configured/reachable, since (like balance deposits) this endpoint has no reason to exist
 * otherwise.
 */
final class ChatFeedbackStore
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connect(): self
    {
        return new self(Database::connect());
    }

    public function isConfigured(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * record(...) - insert one negative-feedback row. $messages is the reconstructed
     * conversation (OpenAI-style {role, content} turns) ending with the disliked assistant
     * reply, stored as JSON. Throws if the database isn't configured/reachable, the messages
     * can't be encoded, or the insert itself fails.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function record(
        string $discordUserId,
        string $discordMessageId,
        ?string $discordChannelId,
        ?string $discordGuildId,
        array $messages
    ): void {
        if ($this->pdo === null) {
            throw new RuntimeException('The feedback database is not configured on this server.');
        }

        $encodedMessages = json_encode($messages, JSON_UNESCAPED_SLASHES);
        if ($encodedMessages === false) {
            throw new RuntimeException('Failed to encode feedback messages.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_feedback
                (discord_user_id, discord_message_id, discord_channel_id, discord_guild_id, messages)
             VALUES
                (:discord_user_id, :discord_message_id, :discord_channel_id, :discord_guild_id, :messages)'
        );
        $stmt->execute([
            ':discord_user_id' => $discordUserId,
            ':discord_message_id' => $discordMessageId,
            ':discord_channel_id' => $discordChannelId,
            ':discord_guild_id' => $discordGuildId,
            ':messages' => $encodedMessages,
        ]);
    }
}
