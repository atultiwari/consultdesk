-- ConsultDesk initial schema (docs/PLAN.md §5).
-- Targets MySQL 8.0.16+ and MariaDB 10.4+. All DATETIME values are UTC; the app sets time_zone = '+00:00'.
-- Tables use IF NOT EXISTS so a run that failed part-way can simply be re-run.

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS providers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    title VARCHAR(160) NULL,
    bio TEXT NULL,
    photo_path VARCHAR(255) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    whatsapp VARCHAR(20) NULL,
    telegram_chat_id VARCHAR(32) NULL,
    upi_vpa VARCHAR(100) NULL,
    upi_payee_name VARCHAR(100) NULL,
    -- Booking rules; editable per provider in the admin panel.
    min_notice_min INT UNSIGNED NOT NULL DEFAULT 1440,
    horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_before SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    buffer_after SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    slot_interval SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    max_per_day SMALLINT UNSIGNED NULL DEFAULT 3,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_providers_slug (slug),
    KEY idx_providers_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'admin', 'provider') NOT NULL,
    provider_id BIGINT UNSIGNED NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT fk_users_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL,
    title VARCHAR(160) NOT NULL,
    tagline VARCHAR(255) NULL,
    description TEXT NULL,
    audience VARCHAR(160) NULL,
    duration_min SMALLINT UNSIGNED NOT NULL,
    price_minor INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    payment_methods JSON NOT NULL,
    questions JSON NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_provider_slug (provider_id, slug),
    CONSTRAINT fk_services_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT chk_services_duration CHECK (duration_min BETWEEN 1 AND 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly windows in the provider's local time. weekday is ISO-8601: 1 = Monday … 7 = Sunday.
-- Rules with a service_id replace the provider's general rules for that service.
CREATE TABLE IF NOT EXISTS availability_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    KEY idx_availability_provider_weekday (provider_id, weekday),
    CONSTRAINT fk_availability_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_availability_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE,
    CONSTRAINT chk_availability_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_availability_window CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- provider_id NULL means an organisation-wide closure (e.g. a public holiday).
CREATE TABLE IF NOT EXISTS blocked_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_blocked_provider_range (provider_id, start_at, end_at),
    CONSTRAINT fk_blocked_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT chk_blocked_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(16) NOT NULL,
    public_token_hash CHAR(64) NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    customer_phone VARCHAR(20) NULL,
    customer_timezone VARCHAR(64) NULL,
    answers JSON NOT NULL,
    amount_minor INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    payment_method ENUM('upi', 'razorpay_link', 'free') NOT NULL,
    utr VARCHAR(22) NULL,
    gateway_ref VARCHAR(64) NULL,
    gateway_payment_id VARCHAR(64) NULL,
    gcal_event_id VARCHAR(255) NULL,
    meet_url VARCHAR(255) NULL,
    status ENUM(
        'held', 'awaiting_verification', 'confirmed', 'rejected', 'expired',
        'cancelled', 'completed', 'no_show', 'rescheduled'
    ) NOT NULL,
    hold_expires_at DATETIME NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    status_changed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_bookings_ref (ref),
    -- One UPI transaction cannot pay for two bookings.
    UNIQUE KEY uq_bookings_utr (utr),
    KEY idx_bookings_provider_status_start (provider_id, status, start_at),
    KEY idx_bookings_status_hold (status, hold_expires_at),
    KEY idx_bookings_gateway_ref (gateway_ref),
    CONSTRAINT fk_bookings_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_bookings_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gateway credentials, encrypted with sodium. provider_id NULL is the organisation default.
CREATE TABLE IF NOT EXISTS payment_gateways (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NULL,
    gateway VARCHAR(32) NOT NULL,
    mode ENUM('test', 'live') NOT NULL DEFAULT 'test',
    key_id VARCHAR(64) NOT NULL,
    secret_enc TEXT NOT NULL,
    webhook_secret_enc TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_gateways_gateway_provider (gateway, provider_id),
    CONSTRAINT fk_gateways_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw webhook deliveries. The unique key makes repeated deliveries a no-op.
CREATE TABLE IF NOT EXISTS payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(32) NOT NULL,
    event_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_payment_events_gateway_event (gateway, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    oauth_provider VARCHAR(32) NOT NULL DEFAULT 'google',
    account_email VARCHAR(254) NULL,
    refresh_token_enc TEXT NOT NULL,
    access_token_enc TEXT NULL,
    access_expires_at DATETIME NULL,
    scopes VARCHAR(500) NULL,
    busy_calendar_ids JSON NULL,
    target_calendar_id VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_provider (provider_id, oauth_provider),
    CONSTRAINT fk_oauth_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Side effects (calendar, email, Telegram) retried by cron.
CREATE TABLE IF NOT EXISTS outbox_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    dedupe_key VARCHAR(191) NULL,
    status ENUM('pending', 'running', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_outbox_dedupe (dedupe_key),
    KEY idx_outbox_status_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARBINARY(16) NOT NULL,
    email VARCHAR(254) NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL,
    KEY idx_login_ip_time (ip, attempted_at),
    KEY idx_login_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id is a SHA-256 of the session cookie value; the raw value is never stored.
CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(64) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    csrf_token_hash CHAR(64) NOT NULL,
    ip VARBINARY(16) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('user', 'system', 'telegram', 'webhook', 'customer') NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    data JSON NULL,
    ip VARBINARY(16) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
