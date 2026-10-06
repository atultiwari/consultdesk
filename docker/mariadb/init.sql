-- LOCAL DEV ONLY: a separate database for PHPUnit integration tests.
CREATE DATABASE IF NOT EXISTS consultdesk_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON consultdesk_test.* TO 'consultdesk'@'%';
