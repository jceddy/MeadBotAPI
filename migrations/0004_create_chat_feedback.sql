-- Run manually against the MeadBotAPI database (not applied automatically by CI/CD).
-- One row per negative-feedback event: a Discord 👎 reaction on one of MeadBot's !chat replies,
-- recorded via POST /api/v1/chat/feedback. messages is the reconstructed conversation (OpenAI-
-- style {role, content} turns) ending with the disliked assistant reply, stored as JSON so the
-- actual exchange can be reviewed later without still having it in Discord.

CREATE TABLE chat_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    discord_user_id VARCHAR(255) NOT NULL,
    discord_message_id VARCHAR(255) NOT NULL,
    discord_channel_id VARCHAR(255) NULL,
    discord_guild_id VARCHAR(255) NULL,
    messages JSON NOT NULL,
    INDEX idx_chat_feedback_created_at (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
