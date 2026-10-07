-- A short label a teacher can put on a session ("Most popular", "New"…), shown on the booking site.
ALTER TABLE services
    ADD COLUMN highlight VARCHAR(24) NULL AFTER audience;
