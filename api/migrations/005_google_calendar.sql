-- Phase 4: Google Calendar per provider. oauth_tokens (001) holds the encrypted tokens.
ALTER TABLE oauth_tokens
    ADD COLUMN status ENUM('active', 'broken') NOT NULL DEFAULT 'active' AFTER target_calendar_id,
    ADD COLUMN last_error VARCHAR(500) NULL AFTER status,
    ADD COLUMN broken_notified_at DATETIME NULL AFTER last_error;

-- In-flight "Connect Google Calendar" requests: the OAuth state (hashed) and the PKCE verifier (encrypted).
CREATE TABLE IF NOT EXISTS google_oauth_states (
    state_hash CHAR(64) NOT NULL PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    verifier_enc VARCHAR(255) NOT NULL,
    -- The Google account the operator said will connect; any other account is refused.
    expected_email VARCHAR(254) NOT NULL,
    allow_replace TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_google_states_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The calendar each booking's event was created on, so it is deleted from the right place.
ALTER TABLE bookings ADD COLUMN gcal_calendar_id VARCHAR(255) NULL AFTER gcal_event_id;

-- Google free/busy per provider per UTC week, kept for 2 minutes (docs/PLAN.md §6.1).
-- busy is NULL when Google could not be reached (remembered for 1 minute).
CREATE TABLE IF NOT EXISTS google_busy_cache (
    provider_id BIGINT UNSIGNED NOT NULL,
    range_start DATETIME NOT NULL,
    range_end DATETIME NOT NULL,
    busy JSON NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (provider_id, range_start, range_end),
    KEY idx_google_busy_fetched (fetched_at),
    CONSTRAINT fk_google_busy_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
