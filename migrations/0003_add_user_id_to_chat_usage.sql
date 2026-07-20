-- Run manually against the MeadBotAPI database (not applied automatically by CI/CD).
-- Adds an optional caller-supplied user identifier to each usage row, from the X-User-Id header
-- on POST /api/v1/chat (NULL when the header was omitted). Indexed since GET
-- /api/v1/balance/usage-by-user groups by it.

ALTER TABLE chat_usage
    ADD COLUMN user_id VARCHAR(255) NULL AFTER model,
    ADD INDEX idx_chat_usage_user_id (user_id);
