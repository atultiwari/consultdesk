-- Phase 2: limit how many open (held / awaiting) bookings one email address can have.
CREATE INDEX idx_bookings_customer_email_status ON bookings (customer_email, status);
