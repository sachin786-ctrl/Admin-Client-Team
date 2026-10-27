<?php
/**
 * SMTP Mail Configuration
 * ------------------------------------------------------------------
 * For Gmail:
 *  1. Enable 2-Step Verification on your Google account.
 *  2. Create an "App Password" -> https://myaccount.google.com/apppasswords
 *  3. Use that 16-character app password below (NOT your normal Gmail password).
 * ------------------------------------------------------------------
 */
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'sachink27263@gmail.com');   // <-- apna Gmail id daalein
define('SMTP_PASSWORD', 'hugq tjzi vfbr mwxx');       // <-- Gmail App Password daalein
define('SMTP_FROM_EMAIL', 'sachink27263@gmail.com');
define('SMTP_FROM_NAME', 'ClientFlow');
define('SMTP_SECURE', 'tls'); // tls or ssl
