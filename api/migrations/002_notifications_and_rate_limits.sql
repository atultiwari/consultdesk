-- Phase 2: provider notification email, encrypted status-page token, request rate limits.

-- Where provider-side emails go (new booking, UTR to verify). The owner gets a copy.
ALTER TABLE providers ADD COLUMN notify_email VARCHAR(254) NULL AFTER telegram_chat_id;

-- The status-page token, encrypted with APP_KEY so every email can carry the link.
-- Lookups still use public_token_hash; a database dump alone cannot open status pages.
ALTER TABLE bookings ADD COLUMN public_token_enc VARCHAR(255) NULL AFTER public_token_hash;

-- Fixed-window request counters. subject is a SHA-256 of the client IP, never the IP itself.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(64) NOT NULL,
    subject CHAR(64) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, subject, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
