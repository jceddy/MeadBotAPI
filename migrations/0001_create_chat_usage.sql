-- Run manually against the MeadBotAPI database (not applied automatically by CI/CD).
-- One row per POST /api/v1/chat request, whether it ultimately succeeded or failed — Fireworks
-- bills per underlying call regardless, so failed requests that made at least one call are
-- recorded too (usage/cost will be zero for a request that failed before any call completed).

CREATE TABLE chat_usage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL,
    error_message TEXT NULL,
    model VARCHAR(255) NOT NULL,
    prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cached_prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd DECIMAL(12, 6) NOT NULL DEFAULT 0,
    INDEX idx_chat_usage_created_at (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
