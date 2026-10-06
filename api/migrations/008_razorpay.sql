-- Phase 7: Razorpay Payment Links. Each online booking gets its own link; its short URL is kept so the
-- status page can send the customer back to it.
ALTER TABLE bookings ADD COLUMN gateway_url VARCHAR(255) NULL AFTER gateway_ref;
