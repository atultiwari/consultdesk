-- Phase 3: Telegram bot. Providers already have telegram_chat_id (001); owners and admins get one too.
ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(32) NULL AFTER provider_id;
CREATE INDEX idx_users_telegram_chat ON users (telegram_chat_id);
CREATE INDEX idx_providers_telegram_chat ON providers (telegram_chat_id);

-- One-time codes for linking a chat: t.me/<bot>?start=<code>. Only a SHA-256 of the code is stored.
CREATE TABLE IF NOT EXISTS telegram_link_codes (
    code_hash CHAR(64) NOT NULL PRIMARY KEY,
    target_type ENUM('provider', 'user') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts sent with action buttons, so they can be edited once the booking is settled anywhere.
CREATE TABLE IF NOT EXISTS telegram_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    chat_id VARCHAR(32) NOT NULL,
    message_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_telegram_messages_chat_message (chat_id, message_id),
    KEY idx_telegram_messages_booking (booking_id),
    CONSTRAINT fk_telegram_messages_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
