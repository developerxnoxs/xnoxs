<?php
/**
 * TelegramBot - Konfigurasi
 * Edit file ini sebelum deploy
 */

define('BOT_TOKEN', '');
define('BOT_USERNAME', 'xnoxsguard_bot');

// Superadmin Telegram User ID (bisa lebih dari satu)
define('SUPERADMINS', [8622949987]);

// Database SQLite path
define('DB_PATH', __DIR__ . '/data/bot.db');

// Log path
define('LOG_PATH', __DIR__ . '/data/bot.log');

// Webhook secret (opsional, untuk keamanan extra)
define('WEBHOOK_SECRET', '');

// Batas flood (pesan per menit)
define('FLOOD_LIMIT', 5);

// Batas warn sebelum kick
define('WARN_LIMIT', 3);

// hCaptcha (untuk verifikasi anggota baru via web)
// Daftar gratis di: https://www.hcaptcha.com/
define('HCAPTCHA_SITEKEY', '');  // Isi dengan Site Key dari dashboard hCaptcha
define('HCAPTCHA_SECRET',  '');  // Isi dengan Secret Key dari dashboard hCaptcha

// Timezone
date_default_timezone_set('Asia/Jakarta');

// API URL Telegram
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// ================================================================
//  KONFIGURASI EMAIL (cPanel) — Default fallback
//  Bisa di-override via Panel Superadmin → Email → ⚙️ Settings
// ================================================================
define('CPANEL_HOST', 'cpanel.xnoxsguard.my.id');
define('CPANEL_PORT', 2083);
define('CPANEL_USER', '');
define('CPANEL_PASS', '');

// Mail server untuk IMAP/SMTP
define('MAIL_HOST', 'webmail.xnoxsguard.my.id');
define('IMAP_PORT', 993);
define('SMTP_PORT', 465);

// ================================================================
//  KONFIGURASI WHM — Default fallback
//  Bisa di-override via Panel Superadmin → WHM → ⚙️ Settings
// ================================================================
define('WHM_HOST',  'cpanel.xnoxsguard.my.id');
define('WHM_PORT',  2087);
define('WHM_USER',  '');
define('WHM_PASS',  '');
define('WHM_TOKEN', ''); // WHM API Token (disarankan, lebih aman dari password)
