-- Phase 6b: user management (invites, disabling) and the System panel.
ALTER TABLE users
    ADD COLUMN password_set_at DATETIME NULL AFTER password_hash,
    ADD COLUMN invited_at DATETIME NULL AFTER password_set_at,
    ADD COLUMN disabled_at DATETIME NULL AFTER last_login_at;

-- Everyone who exists already chose their password.
UPDATE users SET password_set_at = created_at WHERE password_set_at IS NULL;

-- Invites reuse the reset links: an invite lasts two days, a reset 30 minutes.
ALTER TABLE password_resets
    ADD COLUMN purpose ENUM('reset', 'invite') NOT NULL DEFAULT 'reset' AFTER token_hash;
