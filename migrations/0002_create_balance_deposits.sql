-- Run manually against the MeadBotAPI database (not applied automatically by CI/CD).
-- One row per prepaid-balance deposit, recorded via POST /api/v1/balance/deposits whenever the
-- Fireworks account is topped up. amount_usd may be negative for a manual correction to the
-- ledger (e.g. reconciling against Fireworks' own billing dashboard).

CREATE TABLE balance_deposits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount_usd DECIMAL(12, 6) NOT NULL,
    note VARCHAR(500) NULL,
    INDEX idx_balance_deposits_created_at (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
