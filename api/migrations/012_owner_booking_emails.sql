-- An owner can stop getting copies of booking emails (new bookings, payments to verify) when the
-- teacher's own booking address already receives them.
ALTER TABLE users
    ADD COLUMN booking_emails TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
