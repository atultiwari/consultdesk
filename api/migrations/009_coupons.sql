-- Discount coupons. A coupon is site-wide (provider_id NULL, made by the owner or an admin) or a
-- teacher's own (provider_id set), optionally limited to some of the sessions it applies to.
-- Codes are stored in upper case and are unique across the site.
CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    provider_id BIGINT UNSIGNED NULL,
    kind ENUM('percent', 'amount') NOT NULL,
    -- percent: 1–100; amount: paise off
    value INT UNSIGNED NOT NULL,
    service_ids JSON NULL,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    max_uses INT UNSIGNED NULL,
    once_per_email TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(200) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_coupons_code (code),
    KEY idx_coupons_provider (provider_id),
    CONSTRAINT fk_coupons_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A booking keeps the code and the discount it got, even if the coupon is later changed or deleted.
ALTER TABLE bookings
    ADD COLUMN coupon_id BIGINT UNSIGNED NULL AFTER amount_minor,
    ADD COLUMN coupon_code VARCHAR(32) NULL AFTER coupon_id,
    ADD COLUMN discount_minor INT UNSIGNED NOT NULL DEFAULT 0 AFTER coupon_code,
    ADD KEY idx_bookings_coupon (coupon_id, customer_email),
    ADD CONSTRAINT fk_bookings_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL;
