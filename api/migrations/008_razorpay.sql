-- Phase 7: Razorpay Payment Links. Each online booking gets its own link; its short URL is kept so the
-- status page can send the customer back to it.
-- gateway_key_id records which account made the link, so its payment is checked against that account
-- even if the keys are changed afterwards.
ALTER TABLE bookings
    ADD COLUMN gateway_url VARCHAR(255) NULL AFTER gateway_ref,
    ADD COLUMN gateway_key_id VARCHAR(64) NULL AFTER gateway_url;
