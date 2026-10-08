<?php

// ConsultDesk settings. The web installer (/install) writes config.php for you; copy this file to
// config.php only if you set things up by hand. Keep config.php private: it holds your keys.

return [
    'APP_URL' => 'https://book.example.com',
    // php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
    'APP_KEY' => 'base64:CHANGE-ME',
    // 32+ random characters
    'CRON_KEY' => 'CHANGE-ME',
    // The secret first part of the admin area's address: 8–64 lowercase letters, digits or "-"
    'ADMIN_PATH' => 'desk-change-me',

    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'u000000000_consultdesk',
    'DB_USER' => 'u000000000_consultdesk',
    'DB_PASSWORD' => '',

    // Hostinger email: smtp.hostinger.com, port 465 with ssl
    'SMTP_HOST' => 'smtp.hostinger.com',
    'SMTP_PORT' => '465',
    'SMTP_ENCRYPTION' => 'ssl',
    'SMTP_USER' => 'bookings@example.com',
    'SMTP_PASSWORD' => '',
    'MAIL_FROM' => 'bookings@example.com',
    'MAIL_FROM_NAME' => 'Bookings',

    // Optional
    // 'BOOKING_PREFIX' => 'VRL',
    // 'RAZORPAY_KEY_ID' => 'rzp_test_…',
    // 'RAZORPAY_KEY_SECRET' => '…',
    // 'RAZORPAY_WEBHOOK_SECRET' => '…',
    // 'PAYMENTS_LIVE' => '1',   // only once you switch to rzp_live_ keys
    // 'UPDATE_CHANNEL' => 'beta', // also offer pre-releases as in-app updates
    // 'PUBLIC_PATH' => '/home/u000000000/domains/example.com/public_html/book', // the installer sets this
    // 'TELEGRAM_BOT_TOKEN' => '…',
    // 'TELEGRAM_WEBHOOK_SECRET' => '…',
    // 'TELEGRAM_BOT_USERNAME' => '…',
    // 'GOOGLE_CLIENT_ID' => '…',
    // 'GOOGLE_CLIENT_SECRET' => '…',
];
