<?php
/**
 * TelegramBot - Konfigurasi
 * Edit file ini sebelum deploy
 */

define('BOT_TOKEN', '8798938545:AAHFUIEFDMgg47PcEoQsz20OM_elaMjBRGw');
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
define('HCAPTCHA_SITEKEY', 'a45ab59c-8b1a-4512-b77c-0e6e7e3e4ed6');  // Isi dengan Site Key dari dashboard hCaptcha
define('HCAPTCHA_SECRET',  'ES_3de38330936e44e183a5f82d5aad2270');  // Isi dengan Secret Key dari dashboard hCaptcha

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

// ================================================================
//  KONFIGURASI AI (Gemini & DeepSeek Official API)
//  API Key dibaca dari environment variable server (.env / hosting)
// ================================================================
define('GEMINI_API_KEY',   getenv('GEMINI_API_KEY')   ?: ($_ENV['GEMINI_API_KEY']   ?? ''));
define('DEEPSEEK_API_KEY', getenv('DEEPSEEK_API_KEY') ?: ($_ENV['DEEPSEEK_API_KEY'] ?? ''));
define('DEEPSEEK_API_URL', getenv('DEEPSEEK_API_URL') ?: ($_ENV['DEEPSEEK_API_URL'] ?? 'https://api.deepseek.com/v1/chat/completions'));

