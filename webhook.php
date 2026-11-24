<?php
/**
 * ================================================================
 *  TELEGRAM GROUP & CHANNEL MANAGEMENT BOT  v3.1
 *  Pure PHP — No External Library — Webhook
 *  Full Inline Panel — Pending Input System — Captcha Fix
 * ================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/handlers/code_runner.php';
require_once __DIR__ . '/handlers/captcha_image.php';
require_once __DIR__ . '/handlers/email.php';
require_once __DIR__ . '/handlers/whm.php';
require_once __DIR__ . '/handlers/cpanel.php';
require_once __DIR__ . '/handlers/tempmail.php';

if (WEBHOOK_SECRET !== '') {
    $secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secretHeader !== WEBHOOK_SECRET) { http_response_code(403); exit('Forbidden'); }
}

$input = file_get_contents('php://input');
if (empty($input)) { http_response_code(200); exit(); }
$update = json_decode($input, true);
if (!$update) { http_response_code(200); exit(); }

if (isset($update['edited_message'])) { http_response_code(200); exit('OK'); }



http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

ignore_user_abort(true);
set_time_limit(300);


$updateId = (int)($update['update_id'] ?? 0);
if ($updateId > 0) {
    $dbForDedup = Database::getInstance();
    if ($dbForDedup->checkAndMarkUpdateId($updateId)) {
        exit();
    }
}

// ================================================================
class TelegramBot {
    private Database $db;
    private array $update;
    private ?array $message       = null;
    private ?array $callbackQuery = null;
    private int    $chatId        = 0;
    private int    $userId        = 0;
    private string $userFullName  = '';

    // Cache isAdmin per-request: hindari panggilan getChatMember berulang untuk user yang sama
    private static array $adminCheckCache = [];
    private string $userMention   = '';
    private string $currentLang   = 'id';

    /**
     * Terjemahan string berdasarkan bahasa grup saat ini.
     */
    private function t(string $key, array $params = []): string {
        return Lang::t($key, $params);
    }

    /**
     * Muat bahasa berdasarkan settings grup.
     */
    private function loadLang(array $settings = []): void {
        $lang = $settings['language'] ?? 'id';
        $this->currentLang = $lang;
        Lang::load($lang);
    }

    public function __construct(array $update) {
        $this->db     = Database::getInstance();
        $this->update = $update;

        if (isset($update['message'])) {
            $this->message   = $update['message'];
            $this->chatId    = $update['message']['chat']['id'];
            $this->userId    = $update['message']['from']['id'] ?? 0;
            $this->setUserInfo($update['message']['from'] ?? []);
        } elseif (isset($update['callback_query'])) {
            $this->callbackQuery = $update['callback_query'];
            $this->chatId        = $update['callback_query']['message']['chat']['id'];
            $this->userId        = $update['callback_query']['from']['id'];
            $this->setUserInfo($update['callback_query']['from']);
        } elseif (isset($update['edited_message'])) {
            $this->message  = $update['edited_message'];
            $this->chatId   = $update['edited_message']['chat']['id'];
            $this->userId   = $update['edited_message']['from']['id'] ?? 0;
            $this->setUserInfo($update['edited_message']['from'] ?? []);
        } elseif (isset($update['my_chat_member'])) {
            $this->chatId = $update['my_chat_member']['chat']['id'];
            $this->userId = $update['my_chat_member']['from']['id'] ?? 0;
            $this->setUserInfo($update['my_chat_member']['from'] ?? []);
        }
    }

    private function setUserInfo(array $from): void {
        $fn = $from['first_name'] ?? '';
        $ln = $from['last_name']  ?? '';
        $this->userFullName = trim("$fn $ln");
        $un = $from['username'] ?? '';
        $this->userMention  = $un
            ? "@$un"
            : "<a href=\"tg://user?id={$from['id']}\">{$this->userFullName}</a>";
    }

    // ================================================================
    //  API HELPERS
    // ================================================================

    public static function api(string $method, array $params = []): array {
        $ch = curl_init(API_URL . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    public static function send(int $chatId, string $text, array $extra = []): array {
        return self::api('sendMessage', array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /**
     * Kirim pesan dengan rate limiting otomatis untuk broadcast ke banyak grup.
     * Telegram API limit: ~30 msg/detik global, ~1 msg/menit per grup untuk broadcast.
     * Implementasi: 50ms delay antar kirim + auto-retry jika kena 429 Too Many Requests.
     *
     * @see https://core.telegram.org/bots/faq#how-can-i-message-all-of-my-bot-39s-subscribers-at-once
     * @param int    $chatId   Target chat ID
     * @param string $method   Telegram API method ('sendMessage', 'sendPhoto', dll.)
     * @param array  $params   Parameter API lengkap (sudah include chat_id)
     * @param int    $delayUs  Delay dalam mikrodetik antar kirim (default 50000 = 50ms = 20 msg/detik)
     * @return array           Respons API Telegram
     */
    public static function broadcastSend(string $method, array $params, int $delayUs = 50000): array {
        $r = self::api($method, $params);
        // Handle 429 Too Many Requests — Telegram menginformasikan berapa detik harus tunggu
        if (!($r['ok'] ?? false) && ($r['error_code'] ?? 0) === 429) {
            $retryAfter = max(1, (int)($r['parameters']['retry_after'] ?? 5));
            sleep($retryAfter);
            $r = self::api($method, $params); // 1 retry setelah sleep
        }
        usleep($delayUs); // Throttle antar kirim
        return $r;
    }

    /**
     * Kirim foto ke Telegram menggunakan multipart/form-data.
     * $imageData: binary PNG/JPEG string.
     */
    public static function sendPhoto(int $chatId, string $imageData, array $extra = [], string $mimeType = 'image/png'): array {
        $ext = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp'              => 'webp',
            default                   => 'png',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'tgimg_') . '.' . $ext;
        file_put_contents($tmp, $imageData);

        $params = array_merge(['chat_id' => $chatId, 'photo' => new CURLFile($tmp, $mimeType, 'image.' . $ext)], $extra);

        $ch = curl_init(API_URL . 'sendPhoto');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        @unlink($tmp);

        return json_decode($res ?: '{}', true) ?: [];
    }

    public static function copyMessage(int $chatId, int $fromChatId, int $messageId, array $extra = []): array {
        return self::api('copyMessage', array_merge([
            'chat_id'      => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id'   => $messageId,
        ], $extra));
    }

    public static function edit(int $chatId, int $msgId, string $text, array $extra = []): array {
        return self::api('editMessageText', array_merge([
            'chat_id'                  => $chatId,
            'message_id'               => $msgId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public static function editKeyboard(int $chatId, int $msgId, array $keyboard): void {
        self::api('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public static function answerCb(string $id, string $text = '', bool $alert = false): void {
        self::api('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]);
    }

    public static function del(int $chatId, int $msgId): void {
        self::api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
    }

    public static function ban(int $chatId, int $userId, int $until = 0): bool {
        $p = ['chat_id' => $chatId, 'user_id' => $userId];
        if ($until > 0) $p['until_date'] = $until;
        return (bool)(self::api('banChatMember', $p)['ok'] ?? false);
    }

    public static function kick(int $chatId, int $userId): bool {
        $r = self::api('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'until_date' => time() + 40]);
        return (bool)($r['ok'] ?? false);
    }

    public static function unban(int $chatId, int $userId): bool {
        $r = self::api('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
        return (bool)($r['ok'] ?? false);
    }

    public static function mute(int $chatId, int $userId, int $until = 0): bool {
        $p = ['chat_id' => $chatId, 'user_id' => $userId, 'permissions' => [
            'can_send_messages'         => false,
            'can_send_audios'           => false,
            'can_send_documents'        => false,
            'can_send_photos'           => false,
            'can_send_videos'           => false,
            'can_send_video_notes'      => false,
            'can_send_voice_notes'      => false,
            'can_send_polls'            => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
        ]];
        if ($until > 0) $p['until_date'] = $until;
        return (bool)(self::api('restrictChatMember', $p)['ok'] ?? false);
    }

    public static function unmute(int $chatId, int $userId): bool {
        return (bool)(self::api('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $userId,
            'permissions' => [
                'can_send_messages'         => true,
                'can_send_audios'           => true,
                'can_send_documents'        => true,
                'can_send_photos'           => true,
                'can_send_videos'           => true,
                'can_send_video_notes'      => true,
                'can_send_voice_notes'      => true,
                'can_send_polls'            => true,
                'can_send_other_messages'   => true,
                'can_add_web_page_previews' => true,
                'can_invite_users'          => true,
            ],
        ])['ok'] ?? false);
    }

    public static function promote(int $chatId, int $userId): bool {
        return (bool)(self::api('promoteChatMember', [
            'chat_id'                => $chatId,
            'user_id'                => $userId,
            'can_manage_chat'        => true,
            'can_delete_messages'    => true,
            'can_restrict_members'   => true,
            'can_invite_users'       => true,
            'can_pin_messages'       => true,
            'can_manage_video_chats' => true,
        ])['ok'] ?? false);
    }

    public static function demote(int $chatId, int $userId): bool {
        return (bool)(self::api('promoteChatMember', [
            'chat_id'                => $chatId,
            'user_id'                => $userId,
            'can_manage_chat'        => false,
            'can_delete_messages'    => false,
            'can_restrict_members'   => false,
            'can_invite_users'       => false,
            'can_pin_messages'       => false,
            'can_manage_video_chats' => false,
        ])['ok'] ?? false);
    }

    public static function setSlowmode(int $chatId, int $seconds): bool {
        return (bool)(self::api('setChatSlowModeDelay', ['chat_id' => $chatId, 'slow_mode_delay' => $seconds])['ok'] ?? false);
    }

    public static function getChatMember(int $chatId, int $userId): array {
        $r = self::api('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        return $r['result'] ?? [];
    }

    /**
     * Cek apakah user adalah admin/creator di grup.
     *
     * Strategi cache berlapis untuk meminimalkan API call ke Telegram:
     * 1. PHP static array (per-request, nol cost) — reset otomatis setiap webhook
     * 2. DB admin cache (TTL 5 menit, shared antar semua user dalam grup) —
     *    satu getChatAdministrators untuk seluruh grup, bukan getChatMember per user
     * 3. Live API call getChatAdministrators jika cache DB expired
     *
     * Sebelumnya: 1 getChatMember API call per (chatId, userId) per request
     * Sesudah:    1 getChatAdministrators per grup per 5 menit (shared semua user)
     *
     * @see https://core.telegram.org/bots/api#getchatadministrators
     */
    public static function isAdmin(int $chatId, int $userId): bool {
        $key = "$chatId:$userId";

        // Layer 1: per-request static cache (zero cost)
        if (array_key_exists($key, self::$adminCheckCache)) {
            return self::$adminCheckCache[$key];
        }

        $db = Database::getInstance();

        // Layer 2: DB admin cache (5 menit TTL, satu entry per grup)
        $cachedAdmins = $db->getCachedAdmins($chatId);
        if ($cachedAdmins !== null) {
            // Populate static cache untuk semua admin yang sudah diketahui
            foreach ($cachedAdmins as $adminId) {
                self::$adminCheckCache["$chatId:$adminId"] = true;
            }
            $result = in_array($userId, $cachedAdmins, true);
            // Tandai juga non-admin agar tidak repeat DB lookup untuk user sama
            self::$adminCheckCache[$key] = $result;
            return $result;
        }

        // Layer 3: Fetch fresh dari Telegram, simpan ke DB cache
        // getChatAdministrators mengembalikan semua admin sekaligus (lebih efisien dari getChatMember)
        $r        = self::api('getChatAdministrators', ['chat_id' => $chatId]);
        $adminIds = [];
        foreach ($r['result'] ?? [] as $member) {
            $adminIds[] = (int)$member['user']['id'];
        }
        $db->setCachedAdmins($chatId, $adminIds);

        // Populate static cache untuk seluruh admin grup sekaligus
        foreach ($adminIds as $aid) {
            self::$adminCheckCache["$chatId:$aid"] = true;
        }
        $result = in_array($userId, $adminIds, true);
        self::$adminCheckCache[$key] = $result;
        return $result;
    }

    /**
     * Invalidasi cache admin untuk grup tertentu (misal setelah promote/demote).
     * Membersihkan KEDUA layer: static PHP array dan DB cache.
     */
    public static function clearAdminCache(int $chatId = 0): void {
        if ($chatId === 0) {
            self::$adminCheckCache = [];
        } else {
            foreach (array_keys(self::$adminCheckCache) as $key) {
                if (str_starts_with($key, "$chatId:")) unset(self::$adminCheckCache[$key]);
            }
            // Bersihkan juga DB-level cache agar promote/demote langsung berlaku
            Database::getInstance()->invalidateAdminCache($chatId);
        }
    }

    public static function isSA(int $userId): bool {
        if (in_array($userId, SUPERADMINS)) return true;
        return Database::getInstance()->isDynamicSA($userId);
    }

    public static function getMemberCount(int $chatId): int {
        return (int)(self::api('getChatMemberCount', ['chat_id' => $chatId])['result'] ?? 0);
    }

    // ================================================================
    //  MAIN HANDLER
    // ================================================================

    public function handle(): void {
        try {
            // Muat konfigurasi email dari database (override config.php)
            EmailManager::configure([
                'cpanel_host' => $this->db->getBotSetting('email.cpanel_host'),
                'cpanel_port' => $this->db->getBotSetting('email.cpanel_port'),
                'cpanel_user' => $this->db->getBotSetting('email.cpanel_user'),
                'cpanel_pass' => $this->db->getBotSetting('email.cpanel_pass'),
                'mail_host'   => $this->db->getBotSetting('email.mail_host'),
                'imap_port'   => $this->db->getBotSetting('email.imap_port'),
                'smtp_port'   => $this->db->getBotSetting('email.smtp_port'),
            ]);

            // Muat konfigurasi WHM dari database (override config.php)
            WhmManager::configure([
                'whm_host'  => $this->db->getBotSetting('whm.host'),
                'whm_port'  => $this->db->getBotSetting('whm.port'),
                'whm_user'  => $this->db->getBotSetting('whm.user'),
                'whm_pass'  => $this->db->getBotSetting('whm.pass'),
                'whm_token' => $this->db->getBotSetting('whm.token'),
            ]);

            // Muat konfigurasi cPanel dari database (override config.php)
            CpanelManager::configure([
                'cpanel_host' => $this->db->getBotSetting('cpanel.host'),
                'cpanel_port' => $this->db->getBotSetting('cpanel.port'),
                'cpanel_user' => $this->db->getBotSetting('cpanel.user'),
                'cpanel_pass' => $this->db->getBotSetting('cpanel.pass'),
            ]);

            // Muat bahasa untuk private chat dari bot_settings
            if ($this->chatId > 0) {
                $privateLang = $this->db->getBotSetting("user_lang_{$this->userId}", 'id');
                $this->loadLang(['language' => $privateLang]);
            }

            // Mode Maintenance: abaikan semua update kecuali dari Superadmin
            if ($this->db->getBotSetting('maintenance_mode', '0') === '1') {
                if (!self::isSA($this->userId)) {
                    // Jawab callback query agar tombol tidak loading selamanya
                    if ($this->callbackQuery) {
                        self::answerCb($this->callbackQuery['id'], $this->t('sa.maintenance_msg'), true);
                    }
                    return;
                }
            }

            if (isset($this->update['my_chat_member'])) { $this->handleMyChatMember($this->update['my_chat_member']); return; }
            if ($this->callbackQuery)  { $this->handleCallback(); return; }
            if ($this->message)        { $this->handleMessage();  return; }
        } catch (Throwable $e) {
            self::log("ERROR: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine(), 'ERROR');
        }
    }

    // ================================================================
    //  MESSAGE HANDLER
    // ================================================================

    private function handleMessage(): void {
        $msg     = $this->message;
        $type    = $msg['chat']['type'] ?? 'private';
        $text    = $msg['text'] ?? '';
        $msgId   = $msg['message_id'];
        $isGroup = in_array($type, ['group', 'supergroup']);

        // Anggota baru — proses DULU sebelum apapun
        if (!empty($msg['new_chat_members'])) { $this->handleJoin($msg); return; }
        // Anggota keluar
        if (!empty($msg['left_chat_member'])) { $this->handleLeft($msg); return; }

        // Private chat
        if ($type === 'private') {
            // Jika pesan adalah foto/video/dokumen dengan caption berisi command,
            // perlakukan caption sebagai teks command (misal: kirim foto + "/edit instruksi")
            $parseText = $text !== '' ? $text : ($msg['caption'] ?? '');
            [$cmd, $args] = $this->parseCommand($parseText);
            // Cek pending input HANYA jika bukan command (tidak awali '/')
            $hasMedia = isset($msg['photo']) || isset($msg['video']) || isset($msg['document'])
                     || isset($msg['audio']) || isset($msg['animation']) || isset($msg['voice'])
                     || isset($msg['video_note']);

            // ── Deteksi kontak & lokasi (untuk alur verifikasi rules_agree) ──
            if (isset($msg['contact'])) {
                $pending = $this->db->getPendingInput($this->userId);
                if ($pending && $pending['action'] === 'verify_contact') {
                    $this->processPendingInput($pending, '', $msgId);
                }
                return;
            }
            if (isset($msg['location'])) {
                $pending = $this->db->getPendingInput($this->userId);
                if ($pending && $pending['action'] === 'verify_location') {
                    $this->processPendingInput($pending, '', $msgId);
                }
                return;
            }

            if (!$cmd && !empty($text)) {
                $pending = $this->db->getPendingInput($this->userId);
                if ($pending) {
                    $this->processPendingInput($pending, $text, $msgId);
                    return;
                }
            } elseif (!$cmd && $hasMedia) {
                $pending = $this->db->getPendingInput($this->userId);
                if ($pending && in_array($pending['action'], ['sa_broadcast', 'sa_schedule_msg'], true)) {
                    $this->processPendingInput($pending, '', $msgId);
                    return;
                }
            }
            $this->handlePrivate($cmd, $args, $msgId);
            return;
        }

        if (!$isGroup) return;

        // Update info grup
        $this->syncGroupInfo($msg['chat']);
        $this->db->incrementStats($this->chatId, 'total_messages');

        // Catat message_id ke log untuk keperluan clearall/purge (teknik Rose Bot)
        $this->db->logMessageId($this->chatId, $msgId, $this->userId);

        // Cache info user agar @username bisa di-resolve saat /kick /ban dll
        // Sekaligus deteksi perubahan username untuk fitur Sang Mata
        if ($this->userId) {
            $from         = $msg['from'] ?? [];
            $usernameChange = $this->db->checkAndUpdateUsername(
                $this->userId,
                $from['username'] ?? '',
                $from['first_name'] ?? '',
                $from['last_name'] ?? ''
            );
            // Sang Mata: notifikasi ke grup jika username berubah
            if ($usernameChange !== null) {
                $grpSettings = $this->db->getGroupSettings($this->chatId);
                if (!empty($grpSettings['sang_mata_enabled'])) {
                    [$oldUn, $newUn] = $usernameChange;
                    $oldDisplay = $oldUn ? "@$oldUn" : '<i>' . $this->t('sang_mata.no_username') . '</i>';
                    $newDisplay = $newUn ? "@$newUn" : '<i>' . $this->t('sang_mata.no_username') . '</i>';
                    $mention    = $newUn
                        ? "@$newUn"
                        : "<a href=\"tg://user?id={$this->userId}\">{$this->userFullName}</a>";
                    self::send($this->chatId, $this->t('sang_mata.alert', [
                        'mention'  => $mention,
                        'user_id'  => $this->userId,
                        'old_un'   => $oldDisplay,
                        'new_un'   => $newDisplay,
                    ]));
                }
            }
        }

        // Cek captcha yang sudah expired — kick secara lazy saat ada pesan masuk
        $this->checkExpiredCaptchas();

        $settings  = $this->db->getGroupSettings($this->chatId);
        $this->loadLang($settings);
        $isAdm     = self::isAdmin($this->chatId, $this->userId);
        $isSuperAd = self::isSA($this->userId);
        $isPriv    = $isAdm || $isSuperAd;

        // Catat relasi admin secara real-time agar PM settings bisa menampilkan daftar grup
        if ($isAdm && $this->userId > 0) {
            $this->db->recordAdminGroup($this->userId, $this->chatId);
        }

        // Cek pending input admin di grup (hanya jika bukan command)
        if ($isPriv && !empty($text) && $text[0] !== '/') {
            $pending = $this->db->getPendingInput($this->userId);
            if ($pending && (int)$pending['chat_id'] === $this->chatId) {
                $this->processPendingInput($pending, $text, $msgId);
                return;
            }
        }

        // Global ban: hapus pesan & kick
        if (!$isPriv && $this->db->isGbanned($this->userId)) {
            self::del($this->chatId, $msgId);
            self::ban($this->chatId, $this->userId);
            return;
        }

        // Cek user yang masih pending captcha — hapus pesannya & kick jika expired
        if (!$isPriv) {
            $captchaPend = $this->db->fetchOne(
                "SELECT * FROM captcha_pending WHERE chat_id = ? AND user_id = ?",
                [$this->chatId, $this->userId]
            );
            if ($captchaPend) {
                self::del($this->chatId, $msgId);
                if (time() > (int)$captchaPend['expires_at']) {
                    // Expired → kick sekarang
                    if ($captchaPend['message_id']) self::del($this->chatId, (int)$captchaPend['message_id']);
                    self::kick($this->chatId, $this->userId);
                    self::send($this->chatId, $this->t('captcha.expired', ['mention' => $this->userMention]));
                    $this->db->execute("DELETE FROM captcha_pending WHERE chat_id = ? AND user_id = ?", [$this->chatId, $this->userId]);
                }
                return;
            }
        }

        // Readonly mode
        if ($settings['ro_mode'] && !$isPriv) {
            self::del($this->chatId, $msgId);
            return;
        }

        // Service message (hapus jika clean_service aktif)
        // Referensi: https://core.telegram.org/bots/api#message — field khusus Telegram service message
        if ($settings['clean_service']) {
            static $serviceFields = [
                'new_chat_title', 'new_chat_photo', 'delete_chat_photo',
                'group_chat_created', 'supergroup_chat_created', 'channel_chat_created',
                'migrate_to_chat_id', 'migrate_from_chat_id', 'pinned_message',
                'video_chat_scheduled', 'video_chat_started', 'video_chat_ended',
                'message_auto_delete_timer_changed', 'forum_topic_created',
                'forum_topic_edited', 'forum_topic_closed', 'forum_topic_reopened',
                'giveaway', 'giveaway_winners', 'giveaway_completed',
            ];
            foreach ($serviceFields as $sf) {
                if (isset($msg[$sf])) {
                    self::del($this->chatId, $msgId);
                    return;
                }
            }
        }

        if (!$isPriv) {
            // Anti-flood
            if ($settings['antiflood_enabled']) {
                if ($this->db->checkFlood($this->chatId, $this->userId, (int)$settings['antiflood_limit'])) {
                    $this->doFlood($settings, $msgId);
                    return;
                }
            }

            // Anti-forward
            if ($settings['anti_forward'] && (isset($msg['forward_from']) || isset($msg['forward_from_chat']))) {
                self::del($this->chatId, $msgId);
                self::send($this->chatId, $this->t('anti.forward', ['mention' => $this->userMention]));
                $this->recordViolation($settings, 'Anti-Forward');
                return;
            }

            // Lock: sticker
            if ($settings['lock_sticker'] && isset($msg['sticker'])) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock Sticker');
                return;
            }
            // Lock: GIF
            if ($settings['lock_gif'] && isset($msg['animation'])) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock GIF');
                return;
            }
            // Lock: media
            if ($settings['lock_media'] && (isset($msg['photo']) || isset($msg['video']) || isset($msg['document']))) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock Media');
                return;
            }
            // Lock: voice
            if ($settings['lock_voice'] && (isset($msg['voice']) || isset($msg['video_note']))) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock Voice');
                return;
            }
            // Lock: game
            if ($settings['lock_game'] && isset($msg['game'])) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock Game');
                return;
            }
            // Lock: poll
            if ($settings['lock_poll'] && isset($msg['poll'])) {
                self::del($this->chatId, $msgId);
                $this->recordViolation($settings, 'Lock Poll');
                return;
            }

            // Anti-link — cek teks, caption, dan entities (link tersembunyi)
            if ($settings['antilink_enabled']) {
                $wl = json_decode($settings['antilink_whitelist'] ?? '[]', true) ?: [];
                if ($this->messageHasLink($msg, $wl)) {
                    self::del($this->chatId, $msgId);
                    self::send($this->chatId, $this->t('anti.link', ['mention' => $this->userMention]));
                    $this->recordViolation($settings, 'Anti-Link');
                    return;
                }
            }

            // Word filter — cek teks DAN caption (foto/video/dokumen)
            if ($settings['word_filter_enabled']) {
                $caption = $msg['caption'] ?? '';
                $scanText = trim($text . ' ' . $caption);
                if (!empty($scanText)) {
                    $bw = json_decode($settings['banned_words'] ?? '[]', true) ?: [];
                    foreach ($bw as $w) {
                        if ($w !== '' && stripos($scanText, $w) !== false) {
                            self::del($this->chatId, $msgId);
                            self::send($this->chatId, $this->t('anti.word', ['mention' => $this->userMention]));
                            $this->recordViolation($settings, 'Filter Kata');
                            return;
                        }
                    }
                }
            }

            // Anti-spam: deteksi pesan/caption duplikat dalam 60 detik
            if ($settings['antispam_enabled']) {
                $caption  = $msg['caption'] ?? '';
                $spamText = !empty($text) ? $text : $caption;
                if (!empty($spamText) && $this->db->checkSpam($this->chatId, $this->userId, $spamText)) {
                    self::del($this->chatId, $msgId);
                    self::send($this->chatId, $this->t('anti.spam', ['mention' => $this->userMention]));
                    $this->recordViolation($settings, 'Anti-Spam');
                    return;
                }
            }
        }

        // Auto-filter keyword (cek teks dan caption)
        $scanForFilter = !empty($text) ? $text : ($msg['caption'] ?? '');
        if (!empty($scanForFilter)) $this->checkFilters($scanForFilter);

        // Auto-pin #pengumuman / #announcement — hanya admin yang bisa memicu
        if (!empty($text) && $isAdm && (stripos($text, '#pengumuman') !== false || stripos($text, '#announcement') !== false)) {
            self::api('pinChatMessage', ['chat_id' => $this->chatId, 'message_id' => $msgId, 'disable_notification' => false]);
            self::send($this->chatId, $this->t('auto_pin', ['mention' => $this->userMention]));
        }

        // Auto-note: jika diawali #keyword
        if (!empty($text) && $text[0] === '#') {
            $keyword = strtolower(trim(explode(' ', $text, 2)[0], '#'));
            if ($keyword) {
                $note = $this->db->getNote($this->chatId, $keyword);
                if ($note) { $this->sendNote($note); return; }
            }
        }

        // Parse command
        [$cmd, $args] = $this->parseCommand($text);
        if ($cmd) $this->handleGroupCmd($cmd, $args, $msg, $settings, $isPriv, $isAdm);
    }

    // ================================================================
    //  PENDING INPUT PROCESSOR
    // ================================================================

    private function processPendingInput(array $pending, string $text, int $userMsgId): void {
        $action       = $pending['action'];
        $targetChatId = (int)$pending['chat_id'];
        $promptMsgId  = (int)$pending['msg_id'];
        $settsMsgId   = (int)$pending['settings_msg_id'];
        $extra        = $pending['extra'] ?? [];

        // Hapus pesan prompt dan pesan input user
        if ($promptMsgId) self::del($this->chatId, $promptMsgId);
        self::del($this->chatId, $userMsgId);
        $this->db->clearPendingInput($this->userId);

        switch ($action) {
            case 'set_welcome':
                $this->db->updateGroupSetting($targetChatId, 'welcome_message', $text);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.welcome_updated'), $s, 'welcome');
                break;

            case 'set_goodbye':
                $this->db->updateGroupSetting($targetChatId, 'goodbye_message', $text);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.goodbye_updated'), $s, 'goodbye');
                break;

            case 'set_rules':
                $this->db->updateGroupSetting($targetChatId, 'rules', $text);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.rules_updated'), $s, 'rules');
                break;

            case 'set_logchannel':
                $this->db->updateGroupSetting($targetChatId, 'log_channel', trim($text));
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.logch_updated', ['channel' => htmlspecialchars(trim($text))]), $s, 'main');
                break;

            case 'set_antilink_whitelist':
                $wl = array_filter(array_map('trim', explode(',', $text)));
                $this->db->updateGroupSetting($targetChatId, 'antilink_whitelist', json_encode(array_values($wl)));
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.wl_updated'), $s, 'antilink');
                break;

            case 'set_banword':
                $words = array_filter(array_map('strtolower', array_map('trim', explode(',', $text))));
                $s  = $this->db->getGroupSettings($targetChatId);
                $bw = json_decode($s['banned_words'] ?? '[]', true) ?: [];
                foreach ($words as $w) { if ($w && !in_array($w, $bw)) $bw[] = $w; }
                $this->db->updateGroupSetting($targetChatId, 'banned_words', json_encode(array_values($bw)));
                $this->db->updateGroupSetting($targetChatId, 'word_filter_enabled', 1);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.bw_added'), $s, 'wordfilter');
                break;

            case 'set_welcome_button':
                $parts = explode('|', $text, 2);
                if (count($parts) < 2 || !filter_var(trim($parts[1]), FILTER_VALIDATE_URL)) {
                    $r = self::send($this->chatId, $this->t('settings.btn_format_err'));
                    return;
                }
                $s    = $this->db->getGroupSettings($targetChatId);
                $btns = json_decode($s['welcome_buttons'] ?? '[]', true) ?: [];
                $btns[] = ['text' => trim($parts[0]), 'url' => trim($parts[1])];
                $this->db->updateGroupSetting($targetChatId, 'welcome_buttons', json_encode($btns));
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.btn_updated'), $s, 'welcome');
                break;

            case 'set_captcha_timeout':
                $sec = (int)$text;
                if ($sec < 30 || $sec > 300) {
                    $r = self::send($this->chatId, $this->t('settings.captcha_range'));
                    return;
                }
                $this->db->updateGroupSetting($targetChatId, 'captcha_timeout', $sec);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.captcha_timeout_updated', ['sec' => $sec]), $s, 'captcha');
                break;

            case 'set_flood_duration':
                $sec = (int)$text;
                if ($sec < 30 || $sec > 86400) {
                    self::send($this->chatId, $this->t('settings.flood_range'));
                    return;
                }
                $this->db->updateGroupSetting($targetChatId, 'antiflood_duration', $sec);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.flood_dur_updated', ['sec' => $sec]), $s, 'flood');
                break;

            case 'set_raid_limit':
                $n = (int)$text;
                if ($n < 3 || $n > 100) {
                    self::send($this->chatId, $this->t('settings.raid_range'));
                    return;
                }
                $this->db->updateGroupSetting($targetChatId, 'raid_limit', $n);
                $s = $this->db->getGroupSettings($targetChatId);
                $this->replySettings($targetChatId, $settsMsgId, $this->t('settings.raid_limit_updated', ['n' => $n]), $s, 'main');
                break;

            case 'sa_img_limit': {
                $n = (int)trim($text);
                if ($n < 0 || $n > 100) {
                    self::send($this->chatId, $this->t('sa.img_limit_range_err'));
                    return;
                }
                $this->db->setBotSetting('img_daily_limit', (string)$n);
                $doneTxt = $this->t('sa.img_limit_updated', ['limit' => $n === 0 ? '∞' : $n]);
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId,
                        $this->t('sa.img_settings_title', ['limit' => $n === 0 ? '∞' : $n]),
                        ['reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '✏️ Ubah Limit', 'callback_data' => 'sa_img_limit_prompt']],
                            [['text' => '🔙 Superadmin', 'callback_data' => 'sa_panel']],
                        ]])]);
                    self::send($this->chatId, $doneTxt);
                } else {
                    self::send($this->chatId, $doneTxt);
                }
                break;
            }

            case 'sa_broadcast':
                $groups  = $this->db->getAllGroups();
                $ok = $fail = 0;
                $srcMsg = $this->message;

                // Map tipe media → Telegram API method + field name (sama persis dengan cron.php)
                $mediaMap = [
                    'photo'      => ['method' => 'sendPhoto',     'field' => 'photo'],
                    'video'      => ['method' => 'sendVideo',     'field' => 'video'],
                    'document'   => ['method' => 'sendDocument',  'field' => 'document'],
                    'audio'      => ['method' => 'sendAudio',     'field' => 'audio'],
                    'animation'  => ['method' => 'sendAnimation', 'field' => 'animation'],
                    'voice'      => ['method' => 'sendVoice',     'field' => 'voice'],
                    'video_note' => ['method' => 'sendVideoNote', 'field' => 'video_note'],
                ];
                $mediaType = null;
                foreach (array_keys($mediaMap) as $mt) {
                    if (isset($srcMsg[$mt])) { $mediaType = $mt; break; }
                }

                if ($mediaType) {
                    // PENTING: Pesan sudah dihapus dari Telegram sebelum kode ini jalan.
                    // copyMessage() tidak bisa digunakan karena source message sudah tidak ada.
                    // Solusi: Ambil file_id dari data pesan yang masih ada di memory PHP,
                    // lalu kirim ulang menggunakan sendPhoto/sendVideo/dll. (sama dengan cron.php)
                    $caption = $srcMsg['caption'] ?? '';

                    // Ekstrak file_id — photo adalah array, ambil resolusi tertinggi
                    if ($mediaType === 'photo') {
                        $photos = $srcMsg['photo'];
                        $fileId = end($photos)['file_id'];
                    } else {
                        $fileId = $srcMsg[$mediaType]['file_id'] ?? null;
                    }

                    if ($fileId) {
                        $apiMethod = $mediaMap[$mediaType]['method'];
                        $apiField  = $mediaMap[$mediaType]['field'];
                        foreach ($groups as $g) {
                            $params = ['chat_id' => (int)$g['chat_id'], $apiField => $fileId];
                            if ($caption && $mediaType !== 'video_note') {
                                $params['caption']    = $caption;
                                $params['parse_mode'] = 'HTML';
                            }
                            // Rate limit: 50ms antar kirim + auto-retry jika 429
                            $r = self::broadcastSend($apiMethod, $params);
                            if ($r['ok'] ?? false) $ok++; else $fail++;
                        }
                    }
                    $logMessage = "[{$mediaType}]" . ($caption ? " {$caption}" : '');
                } else {
                    // Broadcast teks biasa — rate limited
                    $annText = $this->t('broadcast.announce', ['text' => $text]);
                    foreach ($groups as $g) {
                        $r = self::broadcastSend('sendMessage', [
                            'chat_id'                  => (int)$g['chat_id'],
                            'text'                     => $annText,
                            'parse_mode'               => 'HTML',
                            'disable_web_page_preview' => true,
                        ]);
                        if ($r['ok'] ?? false) $ok++; else $fail++;
                    }
                    $logMessage = $text;
                }

                $this->db->execute(
                    "INSERT INTO broadcast_history (sent_by,message,success_count,fail_count) VALUES(?,?,?,?)",
                    [$this->userId, $logMessage, $ok, $fail]
                );
                $doneTxt = $this->t('broadcast.done', ['ok' => $ok, 'fail' => $fail]);
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId,
                        $this->txtSA() . "\n\n" . $doneTxt,
                        ['reply_markup' => json_encode($this->kbSA())]);
                } else {
                    self::send($this->chatId, $doneTxt);
                }
                break;

            case 'sa_gban_add':
                $parts  = explode(' ', trim($text), 2);
                $uid    = (int)($parts[0] ?? 0);
                $reason = $parts[1] ?? $this->t('error.no_reason');
                if (!$uid) { self::send($this->chatId, $this->t('sa.gban_format_err')); return; }
                $this->db->addGban($uid, $this->userId, $reason);
                foreach ($this->db->getAllGroups() as $g) {
                    self::ban((int)$g['chat_id'], $uid);
                }
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                }
                self::send($this->chatId, $this->t('sa.gban_added', ['uid' => $uid, 'reason' => htmlspecialchars($reason)]));
                break;

            case 'sa_gban_remove':
                $uid = (int)trim($text);
                if (!$uid) { self::send($this->chatId, $this->t('error.invalid_id')); return; }
                $this->db->removeGban($uid);
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                }
                self::send($this->chatId, $this->t('sa.gban_removed', ['uid' => $uid]));
                break;

            // ===== PENDING: TAMBAH SA DINAMIS =====
            case 'sa_addsa':
                if (!in_array($this->userId, SUPERADMINS)) break;
                $parts   = explode(' ', trim($text), 2);
                $newSaId = (int)$parts[0];
                $note    = trim($parts[1] ?? '');
                if (!$newSaId) { self::send($this->chatId, $this->t('error.invalid_id')); break; }
                $this->db->addDynamicSA($newSaId, $this->userId, $note);
                $msg = $this->t('sa.addsa_done', ['uid' => $newSaId])
                     . ($note ? $this->t('sa.addsa_note', ['note' => $note]) : '');
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                    self::send($this->chatId, $msg);
                } else {
                    self::send($this->chatId, $msg);
                }
                break;

            // ===== PENDING: HAPUS SA DINAMIS =====
            case 'sa_rmsa':
                if (!in_array($this->userId, SUPERADMINS)) break;
                $rmId = (int)trim($text);
                if (!$rmId) { self::send($this->chatId, $this->t('error.invalid_id')); break; }
                if (in_array($rmId, SUPERADMINS)) {
                    self::send($this->chatId, $this->t('sa.rmsa_static_err'));
                    break;
                }
                $this->db->removeDynamicSA($rmId);
                $msg = $this->t('sa.rmsa_done', ['uid' => $rmId]);
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                    self::send($this->chatId, $msg);
                } else {
                    self::send($this->chatId, $msg);
                }
                break;

            // ===== PENDING: JADWAL BROADCAST — LANGKAH 1 (terima pesan) =====
            case 'sa_schedule_msg':
                if (!self::isSA($this->userId)) break;
                $srcMsg     = $this->message;
                $schedTypes = ['photo', 'video', 'document', 'audio', 'animation', 'voice', 'video_note'];
                $schedMedia = null;
                $schedFileId = null;
                foreach ($schedTypes as $mt) {
                    if (isset($srcMsg[$mt])) {
                        $schedMedia  = $mt;
                        $schedFileId = $mt === 'photo'
                            ? (end($srcMsg['photo'])['file_id'] ?? null)
                            : ($srcMsg[$mt]['file_id'] ?? null);
                        break;
                    }
                }
                $schedCaption = $srcMsg['caption'] ?? '';
                $schedText    = $srcMsg['text'] ?? '';
                // Simpan info pesan di pending extra, lanjut ke step 2
                $example = date('d/m/Y H:i', strtotime('+1 hour'));
                $r2 = self::send($this->chatId, $this->t('sa.schedule_prompt_time', ['example' => $example]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $p2Id = $r2['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, 0, 'sa_schedule_time', $p2Id, $settsMsgId, [
                    'media_type' => $schedMedia,
                    'file_id'    => $schedFileId,
                    'caption'    => $schedCaption,
                    'message'    => $schedText,
                ]);
                break;

            // ===== PENDING: JADWAL BROADCAST — LANGKAH 2 (terima waktu) =====
            case 'sa_schedule_time':
                if (!self::isSA($this->userId)) break;
                $timeInput  = trim($text);
                $schedAt    = \DateTime::createFromFormat('d/m/Y H:i', $timeInput);
                $example2   = date('d/m/Y H:i', strtotime('+1 hour'));
                if (!$schedAt || $schedAt->getTimestamp() <= time()) {
                    self::send($this->chatId, $this->t('sa.schedule_invalid_time', ['example' => $example2]));
                    break;
                }
                $msgData   = $extra;
                $gc        = count($this->db->getAllGroups());
                $this->db->addBroadcastSchedule(
                    $this->userId,
                    $msgData['message'] ?? '',
                    $msgData['media_type'] ?: null,
                    $msgData['file_id'] ?: null,
                    $msgData['caption'] ?? '',
                    $schedAt->getTimestamp()
                );
                $typeKey  = 'sa.schedule_type_' . ($msgData['media_type'] ?: 'text');
                $typeName = $this->t($typeKey);
                $doneTxt  = $this->t('sa.schedule_saved', [
                    'time'  => $schedAt->format('d/m/Y H:i'),
                    'type'  => $typeName,
                    'count' => $gc,
                ]);
                if ($settsMsgId) {
                    self::edit($this->chatId, $settsMsgId, $this->txtSA() . "\n\n" . $doneTxt,
                        ['reply_markup' => json_encode($this->kbSA())]);
                } else {
                    self::send($this->chatId, $doneTxt);
                }
                break;

            // ===== PENDING: VERIFIKASI KONTAK (Langkah 1) =====
            case 'verify_contact': {
                $contact = $this->message['contact'] ?? null;
                if (!$contact) break;

                $groupChatId = (int)($extra['group_chat_id'] ?? 0);
                $phone       = $contact['phone_number'] ?? '';
                $firstName   = $contact['first_name']   ?? '';

                // Kirim prompt lokasi dengan reply keyboard
                $locKb = json_encode([
                    'keyboard'         => [[['text' => $this->t('rules.verify_btn_location'), 'request_location' => true]]],
                    'resize_keyboard'  => true,
                    'one_time_keyboard'=> true,
                ]);
                self::api('sendMessage', [
                    'chat_id'      => $this->chatId,
                    'text'         => $this->t('rules.verify_location_prompt'),
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $locKb,
                ]);

                // Set pending baru: tunggu lokasi
                $this->db->setPendingInput($this->userId, 0, 'verify_location', 0, 0, [
                    'group_chat_id' => $groupChatId,
                    'rules_msg_id'  => (int)($extra['rules_msg_id'] ?? 0),
                    'phone_number'  => $phone,
                    'contact_name'  => $firstName,
                ]);
                break;
            }

            // ===== PENDING: VERIFIKASI LOKASI (Langkah 2 — selesai) =====
            case 'verify_location': {
                $location = $this->message['location'] ?? null;
                if (!$location) break;

                $groupChatId = (int)($extra['group_chat_id'] ?? 0);
                $rulesMsgId  = (int)($extra['rules_msg_id']  ?? 0);
                $phone       = $extra['phone_number'] ?? '';
                $lat         = (float)($location['latitude']  ?? 0);
                $lon         = (float)($location['longitude'] ?? 0);
                $userInfo    = $this->message['from'] ?? [];
                $firstName   = htmlspecialchars($userInfo['first_name'] ?? ($extra['contact_name'] ?? ''));
                $username    = $userInfo['username'] ?? '';

                // 1. Simpan verifikasi ke database
                $this->db->saveVerification($groupChatId, $this->userId, $firstName, $username, $phone, $lat, $lon);

                // 2. Hapus reply keyboard & kirim konfirmasi DM
                self::api('sendMessage', [
                    'chat_id'      => $this->chatId,
                    'text'         => $this->t('rules.verify_done'),
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode(['remove_keyboard' => true]),
                ]);

                if ($groupChatId) {
                    // 3. Unmute user di grup
                    self::api('restrictChatMember', [
                        'chat_id'     => $groupChatId,
                        'user_id'     => $this->userId,
                        'permissions' => [
                            'can_send_messages'         => true,
                            'can_send_audios'           => true,
                            'can_send_documents'        => true,
                            'can_send_photos'           => true,
                            'can_send_videos'           => true,
                            'can_send_video_notes'      => true,
                            'can_send_voice_notes'      => true,
                            'can_send_polls'            => true,
                            'can_send_other_messages'   => true,
                            'can_add_web_page_previews' => true,
                            'can_invite_users'          => true,
                        ],
                    ]);

                    // 4. Hapus pesan rules di grup
                    if ($rulesMsgId) self::del($groupChatId, $rulesMsgId);

                    // 5. Kirim welcome message jika diaktifkan
                    $grpSettings = $this->db->getGroupSettings($groupChatId);
                    if (!empty($grpSettings['welcome_enabled'])) {
                        $grpChat = ['id' => $groupChatId, 'title' => $grpSettings['title'] ?? ''];
                        $wtext   = $this->fmtWelcome($grpSettings['welcome_message'], $userInfo, $grpChat);
                        $buttons = json_decode($grpSettings['welcome_buttons'] ?? '[]', true) ?: [];
                        $wkb     = null;
                        if (!empty($buttons)) {
                            $rows = array_chunk(array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons), 2);
                            $wkb  = json_encode(['inline_keyboard' => $rows]);
                        }
                        self::send($groupChatId, $wtext, array_filter(['reply_markup' => $wkb]));
                    }

                    // 6. Log ke channel jika ada
                    $logCh = $grpSettings['log_channel'] ?? '';
                    if ($logCh) {
                        $unStr = $username ? "@{$username}" : "<i>-</i>";
                        self::api('sendMessage', [
                            'chat_id'    => $logCh,
                            'text'       => "🔐 <b>Verifikasi Anggota Baru</b>\n"
                                          . "👤 <b>{$firstName}</b> ({$unStr}) — <code>{$this->userId}</code>\n"
                                          . "📱 <code>{$phone}</code>\n"
                                          . "📍 <a href='https://www.google.com/maps?q={$lat},{$lon}'>Lihat di Google Maps</a>\n"
                                          . "🕒 " . date('d/m/Y H:i'),
                            'parse_mode' => 'HTML',
                        ]);
                    }
                }
                break;
            }

            // ===== PENDING: LOOKUP USER =====
            case 'sa_lookup':
                if (!self::isSA($this->userId)) break;
                $q  = trim($text);
                $uc = null;
                if (is_numeric($q)) {
                    $uc = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [(int)$q]);
                } else {
                    $clean = strtolower(ltrim($q, '@'));
                    $uc    = $this->db->fetchOne("SELECT * FROM user_cache WHERE username = ?", [$clean]);
                }
                if (!$uc) {
                    self::send($this->chatId, $this->t('sa.lookup_not_found'));
                    break;
                }
                $uid        = (int)$uc['user_id'];
                $warnCount  = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM warnings WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $banCount   = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM bans WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $muteCount  = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM mutes WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $isGb       = $this->db->isGbanned($uid);
                $isDynSA    = $this->db->isDynamicSA($uid);
                $isStaticSA = in_array($uid, SUPERADMINS);
                $fn         = htmlspecialchars(trim(($uc['first_name'] ?? '') . ' ' . ($uc['last_name'] ?? '')));
                $un         = $uc['username'] ? '@' . $uc['username'] : '<i>' . $this->t('settings.not_set') . '</i>';
                $saStatus   = $isStaticSA ? '👑 <b>Static SA</b>' : ($isDynSA ? '⭐ <b>Dynamic SA</b>' : '—');
                $t = $this->t('sa.lookup_title') . "\n\n"
                   . "👤 " . $this->t('me.name', ['name' => $fn]) . "\n"
                   . "🔖 Username: $un\n"
                   . "🪪 " . $this->t('me.id', ['id' => $uid]) . "\n"
                   . "📅 " . date('d/m/Y H:i', $uc['updated_at']) . "\n\n"
                   . $this->t('sa.stats_warn', ['n' => $warnCount]) . "\n"
                   . $this->t('sa.stats_ban',  ['n' => $banCount])  . "\n"
                   . $this->t('sa.stats_mute', ['n' => $muteCount]) . "\n"
                   . $this->t('me.gban', ['status' => ($isGb ? $this->t('me.gban_yes') : $this->t('me.gban_no'))]) . "\n"
                   . "👑 SA: $saStatus";
                $kbRows = [];
                if (!$isGb) {
                    $kbRows[] = [['text' => '🌐🚫 Global Ban User', 'callback_data' => "sa_quick_gban:$uid"]];
                } else {
                    $kbRows[] = [['text' => '✅ ' . $this->t('gban.undone_mention', ['mention' => '']), 'callback_data' => "sa_quick_ungban:$uid"]];
                }
                self::send($this->chatId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $kbRows])]);
                break;

            // ===== PENDING: BUAT AKUN EMAIL VIA CPANEL =====
            case 'sa_email_create':
                if (!self::isSA($this->userId)) break;
                // Format baru: "email@domain password [label]"
                $parts  = preg_split('/\s+/', trim($text), 3);
                $eEmail = strtolower(trim($parts[0] ?? ''));
                $ePass  = trim($parts[1] ?? '');
                $eLabel = trim($parts[2] ?? '');
                if (!EmailManager::isValidEmail($eEmail) || !$ePass) {
                    self::send($this->chatId, $this->t('email.create_invalid_fmt'),
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('email.kb_back'), 'callback_data' => 'sa_email_accounts']]]])]);
                    break;
                }
                $atPos   = strpos($eEmail, '@');
                $eUser   = substr($eEmail, 0, $atPos);
                $eDomain = substr($eEmail, $atPos + 1);
                $eQuota  = 500;
                $msgSent = self::send($this->chatId, $this->t('email.create_alert', ['email' => $eEmail]));
                $res     = EmailManager::createAccount($eUser, $eDomain, $ePass, $eQuota);
                $statusVal = $res['status'] ?? 0;
                $errors  = $res['errors'] ?? ($res['error'] ?? null);
                if ($statusVal && !$errors) {
                    $this->db->addEmailAccount($eEmail, $eUser, $eDomain, $ePass, $eQuota, $eLabel, $this->userId);
                    $newId = $this->db->getEmailAccountByEmail($eEmail)['id'] ?? 0;
                    $txt   = $this->t('email.create_ok', [
                        'email' => $eEmail,
                        'pass'  => $ePass,
                        'quota' => $eQuota,
                        'label' => $eLabel ? $this->t('email.create_label', ['label' => $eLabel]) : '',
                    ]);
                    $editKb = [['inline_keyboard' => [
                        [['text' => $this->t('email.kb_open_inbox'),   'callback_data' => "sa_email_inbox:{$newId}:0"],
                         ['text' => $this->t('email.kb_back_accounts'), 'callback_data' => 'sa_email_accounts']],
                    ]]];
                    if ($msgSent['result']['message_id'] ?? 0) {
                        self::edit($this->chatId, $msgSent['result']['message_id'], $txt, ['reply_markup' => json_encode($editKb[0])]);
                    } else {
                        self::send($this->chatId, $txt, ['reply_markup' => json_encode($editKb[0])]);
                    }
                } else {
                    $errMsg = is_array($errors) ? implode(', ', $errors) : (string)($errors ?? 'Unknown error');
                    // Jika akun sudah ada di cPanel, simpan saja ke database
                    if (stripos($errMsg, 'exist') !== false || stripos($errMsg, 'ada') !== false) {
                        $this->db->addEmailAccount($eEmail, $eUser, $eDomain, $ePass, $eQuota, $eLabel, $this->userId);
                        $newId = $this->db->getEmailAccountByEmail($eEmail)['id'] ?? 0;
                        $txt   = $this->t('email.create_exists', ['email' => $eEmail, 'pass' => $ePass]);
                        $editKb = ['inline_keyboard' => [
                            [['text' => $this->t('email.kb_open_inbox'),    'callback_data' => "sa_email_inbox:{$newId}:0"],
                             ['text' => $this->t('email.kb_back_accounts'), 'callback_data' => 'sa_email_accounts']],
                        ]];
                        if ($msgSent['result']['message_id'] ?? 0) {
                            self::edit($this->chatId, $msgSent['result']['message_id'], $txt, ['reply_markup' => json_encode($editKb)]);
                        } else {
                            self::send($this->chatId, $txt, ['reply_markup' => json_encode($editKb)]);
                        }
                    } else {
                        $txt = $this->t('email.create_fail', ['error' => htmlspecialchars($errMsg)]);
                        if ($msgSent['result']['message_id'] ?? 0) {
                            self::edit($this->chatId, $msgSent['result']['message_id'], $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                                [['text' => $this->t('email.kb_retry'),        'callback_data' => 'sa_email_create_prompt'],
                                 ['text' => $this->t('email.kb_back'),         'callback_data' => 'sa_email_accounts']],
                            ]])]);
                        } else {
                            self::send($this->chatId, $txt);
                        }
                    }
                }
                break;

            // ===== PENDING: TAMBAH AKUN MANUAL (tanpa cPanel) =====
            case 'sa_email_add_manual':
                if (!self::isSA($this->userId)) break;
                // Format: "email@domain password [label]"
                $parts  = preg_split('/\s+/', trim($text), 3);
                $eEmail = strtolower(trim($parts[0] ?? ''));
                $ePass  = trim($parts[1] ?? '');
                $eLabel = trim($parts[2] ?? '');
                if (!EmailManager::isValidEmail($eEmail) || !$ePass) {
                    self::send($this->chatId, $this->t('email.create_invalid_fmt'),
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('email.kb_back'), 'callback_data' => 'sa_email_accounts']]]])]);
                    break;
                }
                $atPos   = strpos($eEmail, '@');
                $eUser   = substr($eEmail, 0, $atPos);
                $eDomain = substr($eEmail, $atPos + 1);
                $eQuota  = 500;
                $existCheck = $this->db->getEmailAccountByEmail($eEmail);
                if ($existCheck) {
                    self::send($this->chatId, $this->t('email.manual_already_exists', ['email' => $eEmail]),
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('email.kb_back_accounts'), 'callback_data' => 'sa_email_accounts']]]])]);
                    break;
                }
                $this->db->addEmailAccount($eEmail, $eUser, $eDomain, $ePass, $eQuota, $eLabel, $this->userId);
                $newId = $this->db->getEmailAccountByEmail($eEmail)['id'] ?? 0;
                $txt   = $this->t('email.manual_added', [
                    'email' => $eEmail,
                    'pass'  => $ePass,
                    'label' => $eLabel ? $this->t('email.create_label', ['label' => $eLabel]) : '',
                ]);
                self::send($this->chatId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_open_inbox'),    'callback_data' => "sa_email_inbox:{$newId}:0"],
                     ['text' => $this->t('email.kb_back_accounts'), 'callback_data' => 'sa_email_accounts']],
                ]])]);
                break;

            // ===== PENDING: KIRIM EMAIL (COMPOSE) =====
            case 'sa_email_compose':
                if (!self::isSA($this->userId)) break;
                $acctId     = (int)($extra['acct_id'] ?? 0);
                $sendMethod = $extra['method'] ?? 'webmail';
                $ea         = $acctId ? $this->db->getEmailAccount($acctId) : null;
                if (!$ea) {
                    self::send($this->chatId, $this->t('email.not_found_txt'));
                    break;
                }
                // Format: baris 1 = to, baris 2 = subject, baris 3+ = body
                $lines   = explode("\n", $text, 3);
                $to      = trim($lines[0] ?? '');
                $subject = trim($lines[1] ?? '');
                $body    = trim($lines[2] ?? '');
                if (!$to || !$subject || !$body) {
                    self::send($this->chatId, $this->t('email.compose_incomplete'),
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('email.kb_back'), 'callback_data' => "sa_email_compose:{$acctId}"]]]])]);
                    break;
                }
                if (!EmailManager::isValidEmail($to)) {
                    self::send($this->chatId, $this->t('email.compose_invalid_to', ['to' => $to]),
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('email.kb_back'), 'callback_data' => "sa_email_compose:{$acctId}"]]]])]);
                    break;
                }
                $methodLabel = $this->t('email.kb_webmail');
                $fromEmail   = $ea['email'];
                $sentMsg = self::send($this->chatId, $this->t('email.send_alert', ['method' => $methodLabel, 'to' => $to]));
                $result = EmailManager::sendMail($ea['email'], $ea['email_password'], $to, $subject, $body);
                if ($result['success']) {
                    $txt = $this->t('email.send_ok', [
                        'method'  => $methodLabel,
                        'from'    => $fromEmail,
                        'to'      => $to,
                        'subject' => htmlspecialchars($subject),
                    ]);
                    $editKb = ['inline_keyboard' => [
                        [['text' => $this->t('email.kb_resend'), 'callback_data' => "sa_email_compose:{$acctId}"],
                         ['text' => $this->t('email.kb_inbox'),  'callback_data' => "sa_email_inbox:{$acctId}:0"]],
                        [['text' => $this->t('email.kb_back_email_panel'), 'callback_data' => 'sa_email']],
                    ]];
                    if ($sentMsg['result']['message_id'] ?? 0) {
                        self::edit($this->chatId, $sentMsg['result']['message_id'], $txt, ['reply_markup' => json_encode($editKb)]);
                    } else {
                        self::send($this->chatId, $txt, ['reply_markup' => json_encode($editKb)]);
                    }
                } else {
                    $errMsg = htmlspecialchars($result['error'] ?? '');
                    $txt    = $this->t('email.send_fail', ['method' => $methodLabel, 'error' => $errMsg]);
                    $editKb = ['inline_keyboard' => [
                        [['text' => $this->t('email.kb_retry'), 'callback_data' => "sa_email_compose:{$acctId}"],
                         ['text' => $this->t('email.kb_back'),  'callback_data' => "sa_email_acct:{$acctId}"]],
                    ]];
                    if ($sentMsg['result']['message_id'] ?? 0) {
                        self::edit($this->chatId, $sentMsg['result']['message_id'], $txt, ['reply_markup' => json_encode($editKb)]);
                    } else {
                        self::send($this->chatId, $txt, ['reply_markup' => json_encode($editKb)]);
                    }
                }
                break;

            // ===== PENDING: SIMPAN PENGATURAN SERVER EMAIL =====
            case 'sa_email_server_settings':
                if (!self::isSA($this->userId)) break;
                $lines = array_map('trim', explode("\n", $text));
                $fields = [
                    'cpanel_host' => $lines[0] ?? '',
                    'cpanel_user' => $lines[1] ?? '',
                    'cpanel_pass' => $lines[2] ?? '',
                    'mail_host'   => $lines[3] ?? '',
                ];
                $saved = [];
                foreach ($fields as $key => $val) {
                    if ($val !== '') {
                        $this->db->setBotSetting("email.{$key}", $val);
                        $saved[] = $key;
                    }
                }
                // Reload config
                EmailManager::configure([
                    'cpanel_host' => $this->db->getBotSetting('email.cpanel_host'),
                    'cpanel_port' => $this->db->getBotSetting('email.cpanel_port'),
                    'cpanel_user' => $this->db->getBotSetting('email.cpanel_user'),
                    'cpanel_pass' => $this->db->getBotSetting('email.cpanel_pass'),
                    'mail_host'   => $this->db->getBotSetting('email.mail_host'),
                    'imap_port'   => $this->db->getBotSetting('email.imap_port'),
                    'smtp_port'   => $this->db->getBotSetting('email.smtp_port'),
                ]);
                $cfg = EmailManager::getRuntimeConfig();
                $confirmTxt = $this->t('email.server_settings_saved', [
                    'cpanel_host' => $cfg['cpanel_host'],
                    'cpanel_user' => $cfg['cpanel_user'] ?: '-',
                    'mail_host'   => $cfg['mail_host'],
                ]);
                self::send($this->chatId, $confirmTxt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_settings'), 'callback_data' => 'sa_email_server_settings']],
                    [['text' => $this->t('email.kb_back_email_panel'), 'callback_data' => 'sa_email']],
                ]])]);
                break;

            // ===== PENDING: BUAT AKUN FTP VIA WHM =====
            case 'sa_whm_ftp_create':
                if (!self::isSA($this->userId)) break;
                // Format: cpanel_user ftp_user ftp_pass [homedir] [quota_mb]
                // Contoh: myhostinguser john secret123 /home/myhostinguser/ftpjohn 100
                $parts       = preg_split('/\s+/', trim($text));
                $cpanelUser  = trim($parts[0] ?? '');
                $ftpUser     = trim($parts[1] ?? '');
                $ftpPass     = trim($parts[2] ?? '');
                $ftpHomedir  = trim($parts[3] ?? '');
                $ftpQuota    = (int)($parts[4] ?? 0);
                if (!$cpanelUser || !$ftpUser || !$ftpPass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format:\n<code>cpanel_user ftp_user password [homedir] [quota_mb]</code>",
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kembali', 'callback_data' => 'sa_whm']]]]), 'parse_mode' => 'HTML']);
                    break;
                }
                $waitMsg = self::send($this->chatId, "⏳ Membuat akun FTP <code>{$ftpUser}</code> via WHM...", ['parse_mode' => 'HTML']);
                $res = WhmManager::ftpCreate($cpanelUser, $ftpUser, $ftpPass, $ftpHomedir, $ftpQuota);
                $wMsgId = $waitMsg['result']['message_id'] ?? 0;
                if ($res['success'] ?? false) {
                    $txt = "✅ <b>Akun FTP Berhasil Dibuat!</b>\n\n"
                         . "👤 <b>cPanel User:</b> <code>{$cpanelUser}</code>\n"
                         . "🔑 <b>FTP User:</b> <code>{$ftpUser}</code>\n"
                         . "🔒 <b>Password:</b> <code>" . htmlspecialchars($ftpPass) . "</code>\n"
                         . ($ftpHomedir ? "📁 <b>Home:</b> <code>" . htmlspecialchars($ftpHomedir) . "</code>\n" : '')
                         . "📦 <b>Quota:</b> " . ($ftpQuota > 0 ? "{$ftpQuota} MB" : "Unlimited");
                    $kb = ['inline_keyboard' => [
                        [['text' => '📋 Daftar FTP', 'callback_data' => "sa_whm_ftp_list:{$cpanelUser}"]],
                        [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                    ]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal membuat FTP.');
                    $txt = "❌ <b>Gagal membuat akun FTP.</b>\n\n<i>{$errMsg}</i>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔄 Coba Lagi', 'callback_data' => 'sa_whm_ftp_create_prompt'],
                                                   ['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']]]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                }
                break;

            // ===== PENDING: TAMBAH DNS RECORD VIA WHM =====
            case 'sa_whm_dns_add':
                if (!self::isSA($this->userId)) break;
                // Format: domain TYPE nama nilai [ttl] [prioritas_mx]
                // Contoh: example.com A www 1.2.3.4
                //         example.com MX @ mail.example.com. 14400 10
                //         example.com TXT @ "v=spf1 ~all"
                $parts   = preg_split('/\s+/', trim($text), 6);
                $dnsDom  = strtolower(trim($parts[0] ?? ''));
                $dnsType = strtoupper(trim($parts[1] ?? ''));
                $dnsName = trim($parts[2] ?? '');
                $dnsVal  = trim($parts[3] ?? '');
                $dnsTtl  = (int)($parts[4] ?? 14400);
                $dnsPrio = (int)($parts[5] ?? 10);
                $dnsAllowed = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];
                if (!$dnsDom || !in_array($dnsType, $dnsAllowed) || !$dnsName || !$dnsVal) {
                    self::send($this->chatId,
                        "⚠️ <b>Format salah.</b>\n\nGunakan:\n<code>domain TIPE nama nilai [ttl] [prioritas]</code>\n\nTipe yang didukung: <b>A, AAAA, CNAME, MX, TXT, NS</b>\n\n<b>Contoh:</b>\n<code>example.com A www 1.2.3.4\nexample.com MX @ mail.example.com. 14400 10\nexample.com TXT @ \"v=spf1 ~all\"</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]])]);
                    break;
                }
                $waitMsg = self::send($this->chatId, "⏳ Menambahkan DNS record <b>{$dnsType}</b> ke <code>{$dnsDom}</code>...", ['parse_mode' => 'HTML']);
                $res = WhmManager::dnsAddRecord($dnsDom, $dnsType, $dnsName, $dnsVal, $dnsTtl ?: 14400, $dnsPrio ?: 10);
                $wMsgId = $waitMsg['result']['message_id'] ?? 0;
                if ($res['success'] ?? false) {
                    $txt = "✅ <b>DNS Record Berhasil Ditambahkan!</b>\n\n"
                         . "🌐 <b>Domain:</b> <code>{$dnsDom}</code>\n"
                         . "📋 <b>Tipe:</b> <code>{$dnsType}</code>\n"
                         . "🏷️ <b>Nama:</b> <code>" . htmlspecialchars($dnsName) . "</code>\n"
                         . "📌 <b>Nilai:</b> <code>" . htmlspecialchars($dnsVal) . "</code>\n"
                         . "⏱️ <b>TTL:</b> <code>" . ($dnsTtl ?: 14400) . "</code>"
                         . ($dnsType === 'MX' ? "\n⭐ <b>Prioritas:</b> <code>" . ($dnsPrio ?: 10) . "</code>" : '');
                    $kb = ['inline_keyboard' => [
                        [['text' => '📋 Lihat Zone', 'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                        [['text' => '➕ Tambah Record Lagi', 'callback_data' => 'sa_whm_dns_add_prompt']],
                        [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                    ]];
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal menambah record.');
                    $txt = "❌ <b>Gagal menambah DNS record.</b>\n\n<i>{$errMsg}</i>";
                    $kb  = ['inline_keyboard' => [
                        [['text' => '🔄 Coba Lagi', 'callback_data' => 'sa_whm_dns_add_prompt']],
                        [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                    ]];
                }
                if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                break;

            // ===== PENDING: HAPUS DNS RECORD VIA WHM =====
            case 'sa_whm_dns_del':
                if (!self::isSA($this->userId)) break;
                // Format: domain nomor_baris
                // Contoh: example.com 12
                $parts   = preg_split('/\s+/', trim($text));
                $dnsDom  = strtolower(trim($parts[0] ?? ''));
                $dnsLine = (int)($parts[1] ?? 0);
                if (!$dnsDom || $dnsLine <= 0) {
                    self::send($this->chatId,
                        "⚠️ <b>Format salah.</b>\n\nGunakan:\n<code>domain nomor_baris</code>\n\n<b>Contoh:</b>\n<code>example.com 12</code>\n\nℹ️ Nomor baris dapat dilihat di menu <b>Lihat Zone</b>.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]])]);
                    break;
                }
                $waitMsg = self::send($this->chatId, "⏳ Menghapus DNS record baris #{$dnsLine} dari <code>{$dnsDom}</code>...", ['parse_mode' => 'HTML']);
                $res = WhmManager::dnsRemoveRecord($dnsDom, $dnsLine);
                $wMsgId = $waitMsg['result']['message_id'] ?? 0;
                if ($res['success'] ?? false) {
                    $txt = "✅ <b>DNS Record baris #<code>{$dnsLine}</code> berhasil dihapus dari zone <code>" . htmlspecialchars($dnsDom) . "</code>.</b>";
                    $kb  = ['inline_keyboard' => [
                        [['text' => '📋 Lihat Zone', 'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                        [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                    ]];
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal menghapus record.');
                    $txt = "❌ <b>Gagal menghapus DNS record.</b>\n\n<i>{$errMsg}</i>";
                    $kb  = ['inline_keyboard' => [
                        [['text' => '🔄 Coba Lagi', 'callback_data' => 'sa_whm_dns_del_prompt']],
                        [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                    ]];
                }
                if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                break;

            // ===== PENDING: INPUT DOMAIN UNTUK LIHAT DNS ZONE =====
            case 'sa_whm_dns_zone_input':
                if (!self::isSA($this->userId)) break;
                $dnsDom = strtolower(trim($text));
                if (!$dnsDom) { self::send($this->chatId, "⚠️ Domain tidak boleh kosong."); break; }
                $waitMsg = self::send($this->chatId, "⏳ Mengambil DNS record untuk <code>{$dnsDom}</code>...", ['parse_mode' => 'HTML']);
                $records = WhmManager::dnsGetZone($dnsDom);
                $wMsgId  = $waitMsg['result']['message_id'] ?? 0;
                if (isset($records['error'])) {
                    $txt = "❌ <b>Gagal mengambil zone <code>" . htmlspecialchars($dnsDom) . "</code></b>\n\n<i>" . htmlspecialchars($records['error']) . "</i>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]];
                } elseif (empty($records)) {
                    $txt = "📭 <b>Tidak ada record di zone <code>" . htmlspecialchars($dnsDom) . "</code>.</b>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]];
                } else {
                    $txt = "🗂️ <b>DNS Zone: <code>" . htmlspecialchars($dnsDom) . "</code></b>\n\n";
                    $count = 0;
                    foreach ($records as $rec) {
                        $type = $rec['type'] ?? '';
                        if (in_array($type, ['SOA', 'NS', ''])) continue;
                        $line = $rec['Line'] ?? ($rec['line'] ?? '-');
                        $name = htmlspecialchars($rec['name'] ?? '@');
                        $val  = htmlspecialchars($rec['address'] ?? $rec['cname'] ?? $rec['exchange'] ?? $rec['nsdname'] ?? $rec['txtdata'] ?? '-');
                        $ttl  = $rec['ttl'] ?? '';
                        $prio = ($rec['preference'] ?? $rec['priority'] ?? null) !== null
                            ? " (prio: " . ($rec['preference'] ?? $rec['priority']) . ")" : '';
                        $txt .= "• [<code>{$line}</code>] <b>{$type}</b> {$name} → <code>{$val}</code>{$prio} <i>TTL:{$ttl}</i>\n";
                        if (++$count >= 40) { $txt .= "\n<i>...dan lebih banyak. Gunakan WHM panel untuk melihat semua.</i>"; break; }
                    }
                    $kb = ['inline_keyboard' => [
                        [['text' => '➕ Tambah Record', 'callback_data' => 'sa_whm_dns_add_prompt'],
                         ['text' => '🗑️ Hapus Record',  'callback_data' => 'sa_whm_dns_del_prompt']],
                        [['text' => '♻️ Reset Zone',    'callback_data' => "sa_whm_dns_reset_prompt:{$dnsDom}"]],
                        [['text' => '🔄 Refresh',       'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                        [['text' => '🔙 Menu DNS',       'callback_data' => 'sa_whm_dns']],
                    ]];
                }
                if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                break;

            // ===== PENDING: INPUT DOMAIN UNTUK RESET DNS ZONE =====
            case 'sa_whm_dns_reset_input':
                if (!self::isSA($this->userId)) break;
                $dnsDom = strtolower(trim($text));
                if (!$dnsDom) { self::send($this->chatId, "⚠️ Domain tidak boleh kosong."); break; }
                $waitMsg = self::send($this->chatId,
                    "⚠️ <b>Konfirmasi Reset DNS Zone</b>\n\n🌐 Domain: <code>" . htmlspecialchars($dnsDom) . "</code>\n\n⚠️ <b>Aksi ini akan menghapus SEMUA record kustom</b> dan mengembalikan zone ke konfigurasi default cPanel. Lanjutkan?",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '✅ Ya, Reset Sekarang', 'callback_data' => "sa_whm_dns_reset_yes:{$dnsDom}"]],
                        [['text' => '❌ Batal', 'callback_data' => 'sa_whm_dns']],
                    ]])]);
                break;

            // ===== PENDING: SIMPAN SETTINGS WHM =====
            case 'sa_whm_settings':
                if (!self::isSA($this->userId)) break;
                // Format per baris: host | port | user | token_atau_password
                $lines = array_map('trim', explode("\n", $text));
                $whmFields = [
                    'whm.host'  => $lines[0] ?? '',
                    'whm.port'  => $lines[1] ?? '',
                    'whm.user'  => $lines[2] ?? '',
                    'whm.token' => $lines[3] ?? '',
                    'whm.pass'  => $lines[4] ?? '',
                ];
                foreach ($whmFields as $key => $val) {
                    if ($val !== '') $this->db->setBotSetting($key, $val);
                }
                WhmManager::configure([
                    'whm_host'  => $this->db->getBotSetting('whm.host'),
                    'whm_port'  => $this->db->getBotSetting('whm.port'),
                    'whm_user'  => $this->db->getBotSetting('whm.user'),
                    'whm_pass'  => $this->db->getBotSetting('whm.pass'),
                    'whm_token' => $this->db->getBotSetting('whm.token'),
                ]);
                $cfg = WhmManager::getRuntimeConfig();
                $txt = "✅ <b>Settings WHM Disimpan!</b>\n\n"
                     . "🌐 <b>Host:</b> <code>{$cfg['whm_host']}</code>\n"
                     . "🔌 <b>Port:</b> <code>{$cfg['whm_port']}</code>\n"
                     . "👤 <b>User:</b> <code>" . ($cfg['whm_user'] ?: '-') . "</code>\n"
                     . "🔑 <b>Token:</b> " . ($cfg['whm_token'] ? '✅ Tersimpan' : '❌ Kosong') . "\n"
                     . "🔒 <b>Pass:</b> "  . ($cfg['whm_pass']  ? '✅ Tersimpan' : '❌ Kosong');
                self::send($this->chatId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '⚙️ Settings WHM', 'callback_data' => 'sa_whm_settings_view']],
                    [['text' => '🔙 Panel WHM',    'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ===== PENDING: BUAT AKUN FTP VIA CPANEL =====
            case 'sa_cpanel_ftp_create':
                if (!self::isSA($this->userId)) break;
                // Format: ftp_user password [homedir] [quota_mb]
                // Contoh: john secret123 /home/user/ftpjohn 100
                $parts       = preg_split('/\s+/', trim($text));
                $ftpUser     = trim($parts[0] ?? '');
                $ftpPass     = trim($parts[1] ?? '');
                $ftpHomedir  = trim($parts[2] ?? '');
                $ftpQuota    = (int)($parts[3] ?? 0);
                if (!$ftpUser || !$ftpPass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format:\n<code>ftp_user password [homedir] [quota_mb]</code>",
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kembali', 'callback_data' => 'sa_cpanel']]]]), 'parse_mode' => 'HTML']);
                    break;
                }
                $waitMsg = self::send($this->chatId, "⏳ Membuat akun FTP <code>{$ftpUser}</code> via cPanel...", ['parse_mode' => 'HTML']);
                $res = CpanelManager::ftpCreate($ftpUser, $ftpPass, $ftpHomedir, $ftpQuota);
                $wMsgId = $waitMsg['result']['message_id'] ?? 0;
                if ($res['success'] ?? false) {
                    $txt = "✅ <b>Akun FTP Berhasil Dibuat!</b>\n\n"
                         . "🔑 <b>FTP User:</b> <code>{$ftpUser}</code>\n"
                         . "🔒 <b>Password:</b> <code>" . htmlspecialchars($ftpPass) . "</code>\n"
                         . ($ftpHomedir ? "📁 <b>Home:</b> <code>" . htmlspecialchars($ftpHomedir) . "</code>\n" : '')
                         . "📦 <b>Quota:</b> " . ($ftpQuota > 0 ? "{$ftpQuota} MB" : "Unlimited");
                    $kb = ['inline_keyboard' => [
                        [['text' => '📋 Daftar FTP', 'callback_data' => 'sa_cpanel_ftp_list']],
                        [['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']],
                    ]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal membuat FTP.');
                    $txt = "❌ <b>Gagal membuat akun FTP.</b>\n\n<i>{$errMsg}</i>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔄 Coba Lagi', 'callback_data' => 'sa_cpanel_ftp_create_prompt'],
                                                   ['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']]]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                }
                break;

            // ===== PENDING: HAPUS FTP CPANEL =====
            case 'sa_cpanel_ftp_delete':
                if (!self::isSA($this->userId)) break;
                // Format: ftp_login domain
                $parts     = preg_split('/\s+/', trim($text), 2);
                $ftpLogin  = trim($parts[0] ?? '');
                $ftpDomain = trim($parts[1] ?? '');
                if (!$ftpLogin || !$ftpDomain) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format:\n<code>ftp_username domain.com</code>",
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]), 'parse_mode' => 'HTML']);
                    break;
                }
                $res = CpanelManager::ftpDelete($ftpLogin, $ftpDomain);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Akun FTP <code>{$ftpLogin}@{$ftpDomain}</code> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar FTP', 'callback_data' => 'sa_cpanel_ftp_list'],
                             ['text' => '🔙 Menu FTP',   'callback_data' => 'sa_cpanel_ftp']],
                        ]]),
                    ]);
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal menghapus.');
                    self::send($this->chatId, "❌ Gagal menghapus FTP: <i>{$errMsg}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]),
                    ]);
                }
                break;

            // ===== PENDING: UBAH PASSWORD FTP CPANEL =====
            case 'sa_cpanel_ftp_passwd':
                if (!self::isSA($this->userId)) break;
                // Format: ftp_login domain new_password
                $parts     = preg_split('/\s+/', trim($text), 3);
                $ftpLogin  = trim($parts[0] ?? '');
                $ftpDomain = trim($parts[1] ?? '');
                $newPass   = trim($parts[2] ?? '');
                if (!$ftpLogin || !$ftpDomain || !$newPass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format:\n<code>ftp_username domain.com password_baru</code>",
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]), 'parse_mode' => 'HTML']);
                    break;
                }
                $res = CpanelManager::ftpPasswd($ftpLogin, $ftpDomain, $newPass);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Password FTP <code>{$ftpLogin}@{$ftpDomain}</code> berhasil diubah.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar FTP', 'callback_data' => 'sa_cpanel_ftp_list'],
                             ['text' => '🔙 Menu FTP',   'callback_data' => 'sa_cpanel_ftp']],
                        ]]),
                    ]);
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal mengubah password.');
                    self::send($this->chatId, "❌ Gagal ubah password FTP: <i>{$errMsg}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]),
                    ]);
                }
                break;

            // ===== PENDING: ATUR QUOTA FTP CPANEL =====
            case 'sa_cpanel_ftp_quota':
                if (!self::isSA($this->userId)) break;
                // Format: ftp_login domain quota_mb
                $parts     = preg_split('/\s+/', trim($text), 3);
                $ftpLogin  = trim($parts[0] ?? '');
                $ftpDomain = trim($parts[1] ?? '');
                $quota     = isset($parts[2]) ? (int)$parts[2] : -1;
                if (!$ftpLogin || !$ftpDomain || $quota < 0) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format:\n<code>ftp_username domain.com quota_mb</code>",
                        ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]), 'parse_mode' => 'HTML']);
                    break;
                }
                $res = CpanelManager::ftpSetQuota($ftpLogin, $ftpDomain, $quota);
                if ($res['success'] ?? false) {
                    $quotaLabel = $quota === 0 ? 'Unlimited' : "{$quota} MB";
                    self::send($this->chatId, "✅ Quota FTP <code>{$ftpLogin}@{$ftpDomain}</code> berhasil diatur ke <b>{$quotaLabel}</b>.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar FTP', 'callback_data' => 'sa_cpanel_ftp_list'],
                             ['text' => '🔙 Menu FTP',   'callback_data' => 'sa_cpanel_ftp']],
                        ]]),
                    ]);
                } else {
                    $errMsg = htmlspecialchars($res['error'] ?? 'Gagal mengatur quota.');
                    self::send($this->chatId, "❌ Gagal atur quota FTP: <i>{$errMsg}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Menu FTP', 'callback_data' => 'sa_cpanel_ftp']]]]),
                    ]);
                }
                break;

            // ===== PENDING: SIMPAN SETTINGS CPANEL =====
            case 'sa_cpanel_settings':
                if (!self::isSA($this->userId)) break;
                // Format per baris: host | port | user | password
                $lines = array_map('trim', explode("\n", $text));
                $cpFields = [
                    'cpanel.host' => $lines[0] ?? '',
                    'cpanel.port' => $lines[1] ?? '',
                    'cpanel.user' => $lines[2] ?? '',
                    'cpanel.pass' => $lines[3] ?? '',
                ];
                foreach ($cpFields as $key => $val) {
                    if ($val !== '') $this->db->setBotSetting($key, $val);
                }
                CpanelManager::configure([
                    'cpanel_host' => $this->db->getBotSetting('cpanel.host'),
                    'cpanel_port' => $this->db->getBotSetting('cpanel.port'),
                    'cpanel_user' => $this->db->getBotSetting('cpanel.user'),
                    'cpanel_pass' => $this->db->getBotSetting('cpanel.pass'),
                ]);
                $cfg = CpanelManager::getRuntimeConfig();
                $txt = "✅ <b>Settings cPanel Disimpan!</b>\n\n"
                     . "🌐 <b>Host:</b> <code>{$cfg['cpanel_host']}</code>\n"
                     . "🔌 <b>Port:</b> <code>{$cfg['cpanel_port']}</code>\n"
                     . "👤 <b>User:</b> <code>" . ($cfg['cpanel_user'] ?: '-') . "</code>\n"
                     . "🔒 <b>Pass:</b> "  . ($cfg['cpanel_pass']  ? '✅ Tersimpan' : '❌ Kosong');
                self::send($this->chatId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '⚙️ Settings cPanel', 'callback_data' => 'sa_cpanel_settings_view']],
                    [['text' => '🔙 Panel cPanel',    'callback_data' => 'sa_cpanel']],
                ]])]);
                break;

            // ================================================================
            //  PENDING: WHM — MANAJEMEN AKUN
            // ================================================================

            // ===== PENDING: SUSPEND AKUN CPANEL =====
            case 'sa_whm_suspend':
                if (!self::isSA($this->userId)) break;
                $parts   = preg_split('/\s+/', trim($text), 2);
                $whmUser = trim($parts[0] ?? '');
                $reason  = trim($parts[1] ?? '');
                if (!$whmUser) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>username [alasan]</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                $res = WhmManager::suspendAccount($whmUser, $reason);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Akun <code>{$whmUser}</code> berhasil di-<b>suspend</b>." . ($reason ? "\n📝 Alasan: <i>" . htmlspecialchars($reason) . "</i>" : ''), [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '✅ Unsuspend', 'callback_data' => 'sa_whm_unsuspend_prompt'],
                             ['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal suspend.');
                    self::send($this->chatId, "❌ Gagal suspend <code>{$whmUser}</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                }
                break;

            // ===== PENDING: UNSUSPEND AKUN CPANEL =====
            case 'sa_whm_unsuspend':
                if (!self::isSA($this->userId)) break;
                $whmUser = trim($text);
                if (!$whmUser) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>username</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                $res = WhmManager::unsuspendAccount($whmUser);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Akun <code>{$whmUser}</code> berhasil di-<b>unsuspend</b>.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal unsuspend.');
                    self::send($this->chatId, "❌ Gagal unsuspend <code>{$whmUser}</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                }
                break;

            // ===== PENDING: BUAT AKUN CPANEL BARU =====
            case 'sa_whm_create_acct':
                if (!self::isSA($this->userId)) break;
                $lines  = array_map('trim', explode("\n", trim($text)));
                $user   = $lines[0] ?? '';
                $domain = $lines[1] ?? '';
                $pass   = $lines[2] ?? '';
                $plan   = $lines[3] ?? '';
                $email  = $lines[4] ?? '';
                if (!$user || !$domain || !$pass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nMinimal harus ada username, domain, dan password.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                $waitMsg = self::send($this->chatId, "⏳ Membuat akun cPanel <code>{$user}</code>...", ['parse_mode' => 'HTML']);
                $res = WhmManager::createAccount($user, $domain, $pass, $plan, $email);
                $wMsgId = $waitMsg['result']['message_id'] ?? 0;
                if ($res['success'] ?? false) {
                    $txt = "✅ <b>Akun cPanel Berhasil Dibuat!</b>\n\n"
                         . "👤 <b>User:</b> <code>{$user}</code>\n"
                         . "🌐 <b>Domain:</b> <code>{$domain}</code>\n"
                         . "🔌 <b>IP:</b> <code>" . htmlspecialchars($res['ip'] ?? 'N/A') . "</code>\n"
                         . ($plan ? "📋 <b>Plan:</b> <code>{$plan}</code>\n" : '')
                         . ($email ? "📧 <b>Email:</b> <code>{$email}</code>\n" : '');
                    $kb = ['inline_keyboard' => [
                        [['text' => '👥 Daftar Akun', 'callback_data' => 'sa_whm_accounts'],
                         ['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']],
                    ]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat akun.');
                    $txt = "❌ <b>Gagal membuat akun cPanel.</b>\n\n<i>{$err}</i>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔄 Coba Lagi', 'callback_data' => 'sa_whm_create_acct_prompt'],
                                                   ['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]];
                    if ($wMsgId) self::edit($this->chatId, $wMsgId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                    else         self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb), 'parse_mode' => 'HTML']);
                }
                break;

            // ===== PENDING: HAPUS AKUN CPANEL — LANGKAH 1 (ambil username) =====
            case 'sa_whm_remove_acct_get':
                if (!self::isSA($this->userId)) break;
                $whmUser = trim($text);
                if (!$whmUser) {
                    self::send($this->chatId, "⚠️ Username tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                self::send($this->chatId,
                    "⚠️ <b>KONFIRMASI HAPUS AKUN</b>\n\n"
                    . "Kamu akan menghapus akun cPanel:\n"
                    . "👤 <code>{$whmUser}</code>\n\n"
                    . "<b>Semua data akun (file, database, email) akan hilang permanen!</b>\n\n"
                    . "Lanjutkan?",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '✅ Ya, Hapus Sekarang', 'callback_data' => "sa_whm_remove_yes:{$whmUser}"]],
                        [['text' => '❌ Batal',              'callback_data' => 'sa_whm_acct']],
                    ]])]);
                break;

            // ===== PENDING: UBAH PASSWORD AKUN CPANEL =====
            case 'sa_whm_chpass':
                if (!self::isSA($this->userId)) break;
                $parts   = preg_split('/\s+/', trim($text), 2);
                $whmUser = trim($parts[0] ?? '');
                $newPass = trim($parts[1] ?? '');
                if (!$whmUser || !$newPass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>username password_baru</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                $res = WhmManager::changePassword($whmUser, $newPass);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Password akun <code>{$whmUser}</code> berhasil diubah.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal mengubah password.');
                    self::send($this->chatId, "❌ Gagal ubah password <code>{$whmUser}</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                }
                break;

            // ================================================================
            //  PENDING: WHM — PACKAGE MANAGEMENT
            // ================================================================

            // ===== PENDING: BUAT PACKAGE WHM =====
            case 'sa_whm_pkg_add':
                if (!self::isSA($this->userId)) break;
                // Format per baris: nama\nquota_mb\nbwlimit_mb\nmaxftp\nmaxsql\nmaxpop
                // 0 = unlimited
                $lines   = array_map('trim', explode("\n", trim($text)));
                $pkgName = $lines[0] ?? '';
                $quota   = (int)($lines[1] ?? 0);
                $bwlimit = (int)($lines[2] ?? 0);
                $maxftp  = (int)($lines[3] ?? 0);
                $maxsql  = (int)($lines[4] ?? 0);
                $maxpop  = (int)($lines[5] ?? 0);
                if (!$pkgName) {
                    self::send($this->chatId, "⚠️ Nama package tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']]]])]);
                    break;
                }
                $res = WhmManager::addPackage($pkgName, $quota, $bwlimit, $maxftp, $maxsql, $maxpop);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Package <code>" . htmlspecialchars($pkgName) . "</code> berhasil dibuat.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar Package', 'callback_data' => 'sa_whm_pkg_list'],
                             ['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat package.');
                    self::send($this->chatId, "❌ Gagal buat package: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']]]]),
                    ]);
                }
                break;

            // ===== PENDING: MODIFIKASI AKUN CPANEL =====
            case 'sa_whm_modify_acct':
                if (!self::isSA($this->userId)) break;
                $lines   = array_map('trim', explode("\n", trim($text)));
                $mUser   = $lines[0] ?? '';
                $mPlan   = $lines[1] ?? '';
                $mQuota  = $lines[2] ?? '';
                $mBw     = $lines[3] ?? '';
                if (!$mUser) {
                    self::send($this->chatId, "⚠️ Username tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]])]);
                    break;
                }
                $opts = [];
                if ($mPlan  !== '') $opts['plan']    = $mPlan;
                if ($mQuota !== '') $opts['QUOTA']   = (int)$mQuota;
                if ($mBw    !== '') $opts['BWLIMIT'] = (int)$mBw;
                $res = WhmManager::modifyAccount($mUser, $opts);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Akun <code>" . htmlspecialchars($mUser) . "</code> berhasil dimodifikasi.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal modifikasi akun.');
                    self::send($this->chatId, "❌ Gagal modifikasi <code>" . htmlspecialchars($mUser) . "</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']]]]),
                    ]);
                }
                break;

            case 'sa_whm_make_reseller':
                if (!self::isSA($this->userId)) break;
                $rUser = trim($text);
                if (!$rUser) {
                    self::send($this->chatId, "⚠️ Username tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']]]])]);
                    break;
                }
                $res = WhmManager::makeReseller($rUser);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Akun <code>" . htmlspecialchars($rUser) . "</code> berhasil dijadikan <b>reseller</b>.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar Reseller', 'callback_data' => 'sa_whm_reseller_list'],
                             ['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menjadikan reseller.');
                    self::send($this->chatId, "❌ Gagal: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']]]]),
                    ]);
                }
                break;

            // ===== PENDING: HAPUS RESELLER =====
            case 'sa_whm_unsetup_reseller':
                if (!self::isSA($this->userId)) break;
                $rUser = trim($text);
                if (!$rUser) {
                    self::send($this->chatId, "⚠️ Username tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']]]])]);
                    break;
                }
                $res = WhmManager::removeReseller($rUser);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Hak reseller akun <code>" . htmlspecialchars($rUser) . "</code> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus reseller.');
                    self::send($this->chatId, "❌ Gagal: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Reseller', 'callback_data' => 'sa_whm_reseller']]]]),
                    ]);
                }
                break;

            // ================================================================
            //  PENDING: CPANEL — MYSQL DATABASE
            // ================================================================

            // ===== PENDING: BUAT DATABASE MYSQL =====
            case 'sa_cpanel_db_create':
                if (!self::isSA($this->userId)) break;
                $dbName = trim($text);
                if (!$dbName) {
                    self::send($this->chatId, "⚠️ Nama database tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]])]);
                    break;
                }
                $res = CpanelManager::dbCreate($dbName);
                $fullDbName = $res['name'] ?? CpanelManager::addDbPrefix($dbName);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Database <code>" . htmlspecialchars($fullDbName) . "</code> berhasil dibuat.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar DB', 'callback_data' => 'sa_cpanel_db_list'],
                             ['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat database.');
                    self::send($this->chatId, "❌ Gagal buat database <code>" . htmlspecialchars($fullDbName) . "</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                }
                break;

            // ===== PENDING: HAPUS DATABASE MYSQL =====
            case 'sa_cpanel_db_delete':
                if (!self::isSA($this->userId)) break;
                $dbName = trim($text);
                if (!$dbName) {
                    self::send($this->chatId, "⚠️ Nama database tidak boleh kosong.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]])]);
                    break;
                }
                $res = CpanelManager::dbDelete($dbName);
                $fullDbName = $res['name'] ?? CpanelManager::addDbPrefix($dbName);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Database <code>" . htmlspecialchars($fullDbName) . "</code> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar DB', 'callback_data' => 'sa_cpanel_db_list'],
                             ['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus database.');
                    self::send($this->chatId, "❌ Gagal hapus database <code>" . htmlspecialchars($fullDbName) . "</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                }
                break;

            // ===== PENDING: BUAT USER DATABASE MYSQL =====
            case 'sa_cpanel_db_user_create':
                if (!self::isSA($this->userId)) break;
                $parts    = preg_split('/\s+/', trim($text), 2);
                $dbUser   = trim($parts[0] ?? '');
                $dbPass   = trim($parts[1] ?? '');
                if (!$dbUser || !$dbPass) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>username password</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]])]);
                    break;
                }
                $res = CpanelManager::dbUserCreate($dbUser, $dbPass);
                $fullUserName = $res['name'] ?? CpanelManager::addDbPrefix($dbUser);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ User DB <code>" . htmlspecialchars($fullUserName) . "</code> berhasil dibuat.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat user DB.');
                    self::send($this->chatId, "❌ Gagal buat user DB <code>" . htmlspecialchars($fullUserName) . "</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                }
                break;

            // ===== PENDING: GRANT PRIVILEGES DATABASE MYSQL =====
            case 'sa_cpanel_db_grant':
                if (!self::isSA($this->userId)) break;
                $parts  = preg_split('/\s+/', trim($text), 2);
                $dbName = trim($parts[0] ?? '');
                $dbUser = trim($parts[1] ?? '');
                if (!$dbName || !$dbUser) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>nama_database nama_user</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]])]);
                    break;
                }
                $res = CpanelManager::dbUserGrant($dbName, $dbUser);
                $fullGrantDb   = $res['db']   ?? CpanelManager::addDbPrefix($dbName);
                $fullGrantUser = $res['user']  ?? CpanelManager::addDbPrefix($dbUser);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ User <code>" . htmlspecialchars($fullGrantUser) . "</code> diberi akses ALL PRIVILEGES ke database <code>" . htmlspecialchars($fullGrantDb) . "</code>.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal grant privileges.');
                    self::send($this->chatId, "❌ Gagal grant <code>" . htmlspecialchars($fullGrantUser) . "</code> → <code>" . htmlspecialchars($fullGrantDb) . "</code>: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']]]]),
                    ]);
                }
                break;

            // ================================================================
            //  PENDING: CPANEL — SUBDOMAIN
            // ================================================================

            // ===== PENDING: BUAT SUBDOMAIN =====
            case 'sa_cpanel_subdomain_create':
                if (!self::isSA($this->userId)) break;
                $parts  = preg_split('/\s+/', trim($text), 3);
                $sub    = trim($parts[0] ?? '');
                $dom    = trim($parts[1] ?? '');
                $dir    = trim($parts[2] ?? '');
                if (!$sub || !$dom) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>sub domain.com [/public_html/sub]</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']]]])]);
                    break;
                }
                $res = CpanelManager::subdomainCreate($sub, $dom, $dir);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Subdomain <code>{$sub}.{$dom}</code> berhasil dibuat." . ($dir ? "\n📁 Dir: <code>" . htmlspecialchars($dir) . "</code>" : ''), [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar', 'callback_data' => 'sa_cpanel_subdomain_list'],
                             ['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat subdomain.');
                    self::send($this->chatId, "❌ Gagal buat subdomain: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']]]]),
                    ]);
                }
                break;

            // ===== PENDING: HAPUS SUBDOMAIN =====
            case 'sa_cpanel_subdomain_delete':
                if (!self::isSA($this->userId)) break;
                $parts = preg_split('/\s+/', trim($text), 2);
                $sub   = trim($parts[0] ?? '');
                $dom   = trim($parts[1] ?? '');
                if (!$sub || !$dom) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>sub domain.com</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']]]])]);
                    break;
                }
                $res = CpanelManager::subdomainDelete($sub, $dom);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Subdomain <code>{$sub}.{$dom}</code> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar', 'callback_data' => 'sa_cpanel_subdomain_list'],
                             ['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus subdomain.');
                    self::send($this->chatId, "❌ Gagal hapus subdomain: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']]]]),
                    ]);
                }
                break;

            // ================================================================
            //  PENDING: CPANEL — EMAIL FORWARDER
            // ================================================================

            // ===== PENDING: TAMBAH FORWARDER =====
            case 'sa_cpanel_forwarder_create':
                if (!self::isSA($this->userId)) break;
                $parts = preg_split('/\s+/', trim($text), 2);
                $from  = trim($parts[0] ?? '');
                $to    = trim($parts[1] ?? '');
                if (!$from || !$to) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>dari@domain.com ke@gmail.com</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']]]])]);
                    break;
                }
                $res = CpanelManager::forwarderCreate($from, $to);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Forwarder <code>" . htmlspecialchars($from) . "</code> → <code>" . htmlspecialchars($to) . "</code> berhasil dibuat.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar', 'callback_data' => 'sa_cpanel_forwarder_list'],
                             ['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal membuat forwarder.');
                    self::send($this->chatId, "❌ Gagal buat forwarder: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']]]]),
                    ]);
                }
                break;

            // ===== PENDING: HAPUS FORWARDER =====
            case 'sa_cpanel_forwarder_delete':
                if (!self::isSA($this->userId)) break;
                $parts = preg_split('/\s+/', trim($text), 2);
                $from  = trim($parts[0] ?? '');
                $to    = trim($parts[1] ?? '');
                if (!$from || !$to) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan: <code>dari@domain.com ke@gmail.com</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']]]])]);
                    break;
                }
                $res = CpanelManager::forwarderDelete($from, $to);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Forwarder <code>" . htmlspecialchars($from) . "</code> → <code>" . htmlspecialchars($to) . "</code> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar', 'callback_data' => 'sa_cpanel_forwarder_list'],
                             ['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus forwarder.');
                    self::send($this->chatId, "❌ Gagal hapus forwarder: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']]]]),
                    ]);
                }
                break;

            // ================================================================
            //  PENDING: CPANEL — CRON JOBS
            // ================================================================

            // ===== PENDING: TAMBAH CRON =====
            case 'sa_cpanel_cron_add':
                if (!self::isSA($this->userId)) break;
                $cronLine = trim($text);
                $cronParts = preg_split('/\s+/', $cronLine, 6);
                if (count($cronParts) < 6) {
                    self::send($this->chatId, "⚠️ <b>Format salah.</b>\nGunakan format crontab:\n<code>menit jam hari bulan hari-minggu command</code>\n\nContoh:\n<code>* * * * * /usr/bin/php /home/user/cron.php</code>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']]]])]);
                    break;
                }
                [$cronMin, $cronHour, $cronDay, $cronMonth, $cronWeekday, $cronCmd] = $cronParts;
                $res = CpanelManager::cronAdd($cronMin, $cronHour, $cronDay, $cronMonth, $cronWeekday, $cronCmd);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Cron berhasil ditambahkan:\n<code>" . htmlspecialchars($cronLine) . "</code>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar Cron', 'callback_data' => 'sa_cpanel_cron_list'],
                             ['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menambahkan cron.');
                    self::send($this->chatId, "❌ Gagal tambah cron: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']]]]),
                    ]);
                }
                break;

            // ===== PENDING: HAPUS CRON (by nomor dari daftar) =====
            case 'sa_cpanel_cron_delete':
                if (!self::isSA($this->userId)) break;
                $cronNum = (int)trim($text);
                if ($cronNum <= 0) {
                    self::send($this->chatId, "⚠️ Masukkan nomor cron yang valid dari daftar.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '📋 Daftar Cron', 'callback_data' => 'sa_cpanel_cron_list']]]])]);
                    break;
                }
                $cronList = CpanelManager::cronList();
                if (isset($cronList['error'])) {
                    self::send($this->chatId, "❌ Gagal mengambil daftar cron: <i>" . htmlspecialchars($cronList['error']) . "</i>", ['parse_mode' => 'HTML']);
                    break;
                }
                $cronItem = $cronList[$cronNum - 1] ?? null;
                if (!$cronItem) {
                    self::send($this->chatId, "⚠️ Nomor cron <b>{$cronNum}</b> tidak ditemukan.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '📋 Daftar Cron', 'callback_data' => 'sa_cpanel_cron_list']]]])]);
                    break;
                }
                $lineKey = (int)($cronItem['linekey'] ?? 0);
                $res = CpanelManager::cronDelete($lineKey);
                if ($res['success'] ?? false) {
                    self::send($this->chatId, "✅ Cron #<b>{$cronNum}</b> berhasil dihapus.", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar Cron', 'callback_data' => 'sa_cpanel_cron_list'],
                             ['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']],
                        ]]),
                    ]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus cron.');
                    self::send($this->chatId, "❌ Gagal hapus cron: <i>{$err}</i>", [
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']]]]),
                    ]);
                }
                break;

            default:
                self::send($this->chatId, $this->t('error.access_denied'));
                break;
        }
    }

    private function replySettings(int $targetChatId, int $settsMsgId, string $notice, array $s, string $sub): void {
        $txt = '';
        $kb  = [];

        switch ($sub) {
            case 'welcome':
                $txt = $this->txtWelcomeSettings($s);
                $kb  = $this->kbWelcomeSettings($s, $targetChatId);
                break;
            case 'goodbye':
                $txt = $this->txtGoodbyeSettings($s);
                $kb  = $this->kbGoodbyeSettings($s, $targetChatId);
                break;
            case 'captcha':
                $txt = $this->txtCaptchaSettings($s);
                $kb  = $this->kbCaptchaSettings($s, $targetChatId);
                break;
            case 'flood':
                $txt = $this->txtFloodSettings($s);
                $kb  = $this->kbFlood($s, $targetChatId);
                break;
            case 'antilink':
                $txt = $this->txtAntilinkSettings($s);
                $kb  = $this->kbAntilinkSettings($s, $targetChatId);
                break;
            case 'wordfilter':
                $txt = $this->txtWordFilterSettings($s);
                $kb  = $this->kbWordFilterSettings($s, $targetChatId);
                break;
            case 'rules':
                $txt = $this->t('rules.title') . "\n\n" . (htmlspecialchars($s['rules']) ?: '<i>' . $this->t('settings.not_set') . '</i>');
                $kb  = $this->kbRulesSettings($s, $targetChatId);
                break;
            case 'violation':
                $txt = $this->txtViolationSettings($s);
                $kb  = $this->kbViolationSettings($s, $targetChatId);
                break;
            case 'votekick':
                $txt = $this->txtVotekickSettings($s);
                $kb  = $this->kbVotekickSettings($s, $targetChatId);
                break;
            case 'ai':
                $txt = $this->txtAiSettings($s);
                $kb  = $this->kbAiSettings($s, $targetChatId);
                break;
            default:
                $txt = $this->txtSettings($s, $targetChatId);
                $kb  = $this->kbSettings($s, $targetChatId);
        }

        if ($settsMsgId) {
            self::api('sendMessage', [
                'chat_id'      => $this->chatId,
                'text'         => $notice,
                'parse_mode'   => 'HTML',
            ]);
            self::edit($this->chatId, $settsMsgId, $txt, ['reply_markup' => json_encode($kb)]);
        } else {
            self::send($this->chatId, $notice);
            $r = self::send($this->chatId, $txt, ['reply_markup' => json_encode($kb)]);
        }
    }

    // ================================================================
    //  SCAN ADMIN GROUPS — aktif cek status admin via Telegram API
    //  Dipakai saat user /settings di PM agar tidak perlu kirim pesan di grup dulu
    // ================================================================

    private function scanAdminGroups(): array {
        $allGroups = $this->db->getAllGroups();
        // Batasi ke 100 grup terbaru agar tidak timeout
        $allGroups = array_slice($allGroups, 0, 100);
        foreach ($allGroups as $grp) {
            $cid = (int)$grp['chat_id'];
            $res    = self::api('getChatMember', ['chat_id' => $cid, 'user_id' => $this->userId]);
            $status = $res['result']['status'] ?? '';
            if (in_array($status, ['creator', 'administrator'], true)) {
                $this->db->recordAdminGroup($this->userId, $cid, true);
            } else {
                // Hapus record lama jika user sudah tidak admin
                $this->db->removeAdminGroup($this->userId, $cid);
            }
        }
        return $this->db->getGroupsForAdmin($this->userId);
    }

    // Helper: tampilkan daftar grup untuk settings (setelah scan)
    private function buildGroupListKeyboard(array $groups): array {
        $rows = [];
        foreach ($groups as $grp) {
            $title  = $grp['title'] ?: ('Group ' . $grp['chat_id']);
            $rows[] = [['text' => '🏠 ' . $title, 'callback_data' => 'pm_settings:' . $grp['chat_id']]];
        }
        $rows[] = [
            ['text' => $this->t('pm_settings.btn_rescan'), 'callback_data' => 'rescan_groups'],
            ['text' => $this->t('kb.back'),                'callback_data' => 'menu_main'],
        ];
        return ['inline_keyboard' => $rows];
    }

    // ================================================================
    //  PRIVATE CHAT HANDLER
    // ================================================================

    private function handlePrivate(string $cmd, array $args, int $msgId): void {
        switch ($cmd) {
            case 'start': {
                // Deep link verifikasi math captcha — user klik tombol "Mulai Bot" dari grup
                if (!empty($args[0]) && strpos($args[0], 'mathcap_') === 0) {
                    $token = substr($args[0], strlen('mathcap_'));
                    $row   = $this->db->fetchOne(
                        "SELECT * FROM captcha_pending WHERE hcaptcha_token = ? AND answer > 0 LIMIT 1",
                        [$token]
                    );
                    if ($row && (int)$row['expires_at'] > time()) {
                        $ans  = (int)$row['answer'];
                        // Buat soal baru dengan jawaban yang sama (a + b = ans)
                        $a    = rand(1, min($ans - 1, 15));
                        $b    = $ans - $a;
                        $opts = [$ans];
                        $att  = 0;
                        while (count($opts) < 4 && $att < 30) {
                            $off  = rand(1, 6) * (rand(0, 1) ? 1 : -1);
                            $fake = $ans + $off;
                            if ($fake > 0 && $fake !== $ans && !in_array($fake, $opts)) $opts[] = $fake;
                            $att++;
                        }
                        while (count($opts) < 4) {
                            $fake = rand(1, 30);
                            if (!in_array($fake, $opts)) $opts[] = $fake;
                        }
                        shuffle($opts);
                        $kb = ['inline_keyboard' => [
                            array_map(fn($v) => ['text' => "  $v  ", 'callback_data' => "captcha_verify:$v"], array_slice($opts, 0, 2)),
                            array_map(fn($v) => ['text' => "  $v  ", 'callback_data' => "captcha_verify:$v"], array_slice($opts, 2, 2)),
                        ]];
                        $expTime = date('H:i:s', (int)$row['expires_at']);
                        $caption = $this->t('captcha.math_dm_msg', [
                            'name' => htmlspecialchars($row['first_name'] ?? ''),
                            'time' => $expTime,
                        ]);
                        $imgData = CaptchaImage::generate($a, $b);
                        self::sendPhoto($this->chatId, $imgData, [
                            'caption'      => $caption,
                            'parse_mode'   => 'HTML',
                            'reply_markup' => json_encode($kb),
                        ]);
                    } else {
                        self::send($this->chatId, $this->t('captcha.hcaptcha_expired_dm'));
                    }
                    break;
                }

                // Deep link verifikasi hCaptcha — user klik tombol "Mulai Bot" dari grup
                if (!empty($args[0]) && strpos($args[0], 'hcaptcha_') === 0) {
                    $token = substr($args[0], strlen('hcaptcha_'));
                    $row   = $this->db->fetchOne(
                        "SELECT * FROM captcha_pending WHERE hcaptcha_token = ? LIMIT 1",
                        [$token]
                    );
                    if ($row && (int)$row['expires_at'] > time()) {
                        $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $verifyUrl = "$scheme://$host/captcha_web.php?token=$token";
                        $kb = ['inline_keyboard' => [[
                            ['text' => $this->t('captcha.hcaptcha_btn'), 'url' => $verifyUrl],
                        ]]];
                        self::send($this->chatId, $this->t('captcha.hcaptcha_dm_msg', [
                            'name' => htmlspecialchars($row['first_name'] ?? ''),
                        ]), ['reply_markup' => json_encode($kb)]);
                    } else {
                        self::send($this->chatId, $this->t('captcha.hcaptcha_expired_dm'));
                    }
                    break;
                }
                self::send($this->chatId, $this->txtStart(), ['reply_markup' => json_encode($this->kbMain())]);
                break;
            }
            case 'help': {
                $cat = $this->resolveHelpCategory($args[0] ?? 'overview');
                self::send($this->chatId, $this->txtHelp($cat), ['reply_markup' => json_encode($this->kbHelp($cat, true))]);
                break;
            }
            case 'features':
            case 'fitur':
                self::send($this->chatId, $this->txtFeatures(), ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('start.btn_help'), 'callback_data' => 'menu_help'],
                     ['text' => $this->t('kb.back'),        'callback_data' => 'menu_main']],
                ]])]);
                break;

            // ===== PM SETTINGS: PILIH GRUP =====
            case 'settings':
            case 'mygroupsettings': {
                // Kirim pesan "scanning..." dulu agar user tahu bot sedang bekerja
                $scanMsg = self::send($this->chatId, $this->t('pm_settings.scanning'));
                $scanMsgId = $scanMsg['result']['message_id'] ?? 0;

                // Scan aktif via Telegram API — tidak perlu user kirim pesan di grup dulu
                $groups = $this->scanAdminGroups();

                $kb = $this->buildGroupListKeyboard($groups);
                if (empty($groups)) {
                    $noGroupKb = ['inline_keyboard' => [[
                        ['text' => $this->t('pm_settings.btn_rescan'), 'callback_data' => 'rescan_groups'],
                        ['text' => $this->t('kb.back'),                'callback_data' => 'menu_main'],
                    ]]];
                    if ($scanMsgId) {
                        self::api('editMessageText', ['chat_id' => $this->chatId, 'message_id' => $scanMsgId,
                            'text' => $this->t('pm_settings.no_groups'), 'parse_mode' => 'HTML',
                            'reply_markup' => json_encode($noGroupKb)]);
                    } else {
                        self::send($this->chatId, $this->t('pm_settings.no_groups'), ['reply_markup' => json_encode($noGroupKb)]);
                    }
                    break;
                }
                if ($scanMsgId) {
                    self::api('editMessageText', ['chat_id' => $this->chatId, 'message_id' => $scanMsgId,
                        'text' => $this->t('pm_settings.choose_group'), 'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($kb)]);
                } else {
                    self::send($this->chatId, $this->t('pm_settings.choose_group'), ['reply_markup' => json_encode($kb)]);
                }
                break;
            }

            case 'superadmin':
            case 'sa':
                if (self::isSA($this->userId))
                    self::send($this->chatId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                else
                    self::send($this->chatId, $this->t('error.access_denied'));
                break;

            // ===== SUPERADMIN: EMAIL PANEL =====
            case 'email':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                self::send($this->chatId, $this->txtEmailPanel(), ['reply_markup' => json_encode($this->kbEmailPanel())]);
                break;

            // ===== SUPERADMIN: WHM PANEL =====
            case 'whm':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                self::send($this->chatId, $this->txtWhmPanel(), ['reply_markup' => json_encode($this->kbWhmPanel())]);
                break;

            // ===== SUPERADMIN: CPANEL PANEL =====
            case 'cpanel':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                self::send($this->chatId, $this->txtCpanelPanel(), ['reply_markup' => json_encode($this->kbCpanelPanel())]);
                break;

            // ===== SUPERADMIN: TEMPMAIL =====
            case 'tempmail':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                TempMailBot::handleCommand($this->chatId, $this->userId, $this->db);
                break;

            // ===== SUPERADMIN: PING =====
            case 'ping':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $t0 = microtime(true);
                $me = self::api('getMe');
                $ms = round((microtime(true) - $t0) * 1000);
                self::send($this->chatId, $this->t('sa.ping', ['ms' => $ms, 'username' => ($me['result']['username'] ?? '?')]));
                break;

            // ===== SUPERADMIN: INFO SERVER/VPS/HOSTING =====
            case 'sysinfo':
            case 'serverinfo':
            case 'hostinfo':
            case 'vpsinfo':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                self::send($this->chatId, $this->txtSysInfo(), [
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.sysinfo_refresh_btn'), 'callback_data' => 'sa_sysinfo']],
                        [['text' => $this->t('sa.sysinfo_back_btn'),    'callback_data' => 'sa_panel']],
                    ]]),
                ]);
                break;

            // ===== SUPERADMIN: INFO BOT =====
            case 'sainfo':
            case 'botstat':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                self::send($this->chatId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                break;

            // ===== SUPERADMIN: BACKUP DATABASE =====
            case 'backup':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $dbPath = DB_PATH;
                if (!file_exists($dbPath)) { self::send($this->chatId, $this->t('error.fetch_failed')); break; }
                $size = number_format(filesize($dbPath) / 1024, 1);
                $curl = curl_init(API_URL . 'sendDocument');
                curl_setopt_array($curl, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => [
                        'chat_id'    => $this->chatId,
                        'document'   => new CURLFile($dbPath, 'application/octet-stream', 'bot_backup_' . date('Ymd_His') . '.db'),
                        'caption'    => $this->t('sa.backup_caption', ['date' => date('d/m/Y H:i:s'), 'size' => $size]),
                        'parse_mode' => 'HTML',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                ]);
                curl_exec($curl);
                curl_close($curl);
                break;

            // ===== SUPERADMIN: LIHAT LOG =====
            case 'logs':
            case 'salog':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $n = min(max((int)($args[0] ?? 30), 5), 100);
                $logPath = LOG_PATH;
                if (!file_exists($logPath) || filesize($logPath) === 0) { self::send($this->chatId, $this->t('sa.logs_empty')); break; }
                $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $last  = array_slice($lines, -$n);
                $text  = $this->t('sa.logs_title', ['n' => $n]) . "\n\n<pre>" . htmlspecialchars(implode("\n", $last)) . "</pre>";
                if (strlen($text) > 4000) {
                    $last = array_slice($lines, -15);
                    $text = $this->t('sa.logs_title', ['n' => 15]) . "\n\n<pre>" . htmlspecialchars(implode("\n", $last)) . "</pre>";
                }
                self::send($this->chatId, $text);
                break;

            // ===== SUPERADMIN: MAINTENANCE MODE =====
            case 'maintenance':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $cur = $this->db->getBotSetting('maintenance_mode', '0');
                $new = $cur === '1' ? '0' : '1';
                $this->db->setBotSetting('maintenance_mode', $new);
                self::send($this->chatId, $this->t($new === '1' ? 'sa.maintenance_on' : 'sa.maintenance_off'));
                break;

            // ===== SUPERADMIN: TAMBAH SA =====
            case 'addsa':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                if (!in_array($this->userId, SUPERADMINS)) { self::send($this->chatId, $this->t('error.owner_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('sa.addsa_usage')); break; }
                $newSaId = (int)$args[0];
                if (!$newSaId) { self::send($this->chatId, $this->t('error.invalid_id')); break; }
                $note = implode(' ', array_slice($args, 1));
                $this->db->addDynamicSA($newSaId, $this->userId, $note);
                self::send($this->chatId, $this->t('sa.addsa_done', ['uid' => $newSaId])
                    . ($note ? $this->t('sa.addsa_note', ['note' => $note]) : ''));
                break;

            // ===== SUPERADMIN: HAPUS SA =====
            case 'rmsa':
            case 'removesa':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                if (!in_array($this->userId, SUPERADMINS)) { self::send($this->chatId, $this->t('error.owner_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('sa.rmsa_usage')); break; }
                $rmSaId = (int)$args[0];
                if (in_array($rmSaId, SUPERADMINS)) { self::send($this->chatId, $this->t('sa.rmsa_static_err')); break; }
                $this->db->removeDynamicSA($rmSaId);
                self::send($this->chatId, $this->t('sa.rmsa_done', ['uid' => $rmSaId]));
                break;

            // ===== SUPERADMIN: DAFTAR SA =====
            case 'salist':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $staticSAs  = SUPERADMINS;
                $dynamicSAs = $this->db->getDynamicSuperadmins();
                $t = $this->t('sa.salist_title') . "\n\n" . $this->t('sa.salist_static') . "\n";
                foreach ($staticSAs as $sid) $t .= "• <code>$sid</code>\n";
                if (!empty($dynamicSAs)) {
                    $t .= "\n" . $this->t('sa.salist_dynamic') . "\n";
                    foreach ($dynamicSAs as $d) {
                        $addedFmt = date('d/m/Y', $d['added_at']);
                        $t .= "• <code>{$d['user_id']}</code>" . ($d['note'] ? " — {$d['note']}" : '') . " <i>($addedFmt)</i>\n";
                    }
                } else {
                    $t .= "\n" . $this->t('sa.no_dyn_sa');
                }
                self::send($this->chatId, $t);
                break;

            // ===== SUPERADMIN: LOOKUP USER =====
            case 'lookup':
            case 'cariuser':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('sa.lookup_usage')); break; }
                $q  = trim($args[0]);
                $uc = null;
                if (is_numeric($q)) {
                    $uc = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [(int)$q]);
                } else {
                    $clean = strtolower(ltrim($q, '@'));
                    $uc = $this->db->fetchOne("SELECT * FROM user_cache WHERE username = ?", [$clean]);
                }
                if (!$uc) { self::send($this->chatId, $this->t('sa.lookup_not_found')); break; }
                $uid       = (int)$uc['user_id'];
                $warnCount = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM warnings WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $banCount  = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM bans WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $muteCount = (int)(($this->db->fetchOne("SELECT COUNT(*) as c FROM mutes WHERE user_id=?", [$uid]) ?? [])['c'] ?? 0);
                $isGb      = $this->db->isGbanned($uid);
                $isDynSA   = $this->db->isDynamicSA($uid);
                $isStaticSA = in_array($uid, SUPERADMINS);
                $fn = htmlspecialchars(trim(($uc['first_name'] ?? '') . ' ' . ($uc['last_name'] ?? '')));
                $un = $uc['username'] ? '@' . $uc['username'] : '<i>' . $this->t('settings.not_set') . '</i>';
                $saStatus = $isStaticSA ? '👑 <b>Static SA</b>' : ($isDynSA ? '⭐ <b>Dynamic SA</b>' : '—');
                $t = $this->t('sa.lookup_title') . "\n\n"
                   . "👤 " . $this->t('me.name', ['name' => $fn]) . "\n"
                   . "🔖 Username: $un\n"
                   . "🪪 " . $this->t('me.id', ['id' => $uid]) . "\n"
                   . "📅 " . date('d/m/Y H:i', $uc['updated_at']) . "\n\n"
                   . $this->t('sa.stats_warn', ['n' => $warnCount]) . "\n"
                   . $this->t('sa.stats_ban',  ['n' => $banCount])  . "\n"
                   . $this->t('sa.stats_mute', ['n' => $muteCount]) . "\n"
                   . $this->t('me.gban', ['status' => ($isGb ? $this->t('me.gban_yes') : $this->t('me.gban_no'))]) . "\n"
                   . "👑 SA: $saStatus";
                $kbRows = [];
                if (!$isGb) {
                    $kbRows[] = [['text' => '🌐🚫 Global Ban', 'callback_data' => "sa_quick_gban:$uid"]];
                } else {
                    $kbRows[] = [['text' => '✅ ' . $this->t('sa.ungban_btn'), 'callback_data' => "sa_quick_ungban:$uid"]];
                }
                self::send($this->chatId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $kbRows])]);
                break;

            // ===== SUPERADMIN: MASS BAN =====
            case 'massban':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                if (empty($args)) { self::send($this->chatId, $this->t('sa.massban_usage')); break; }
                $cnt = 0;
                foreach ($args as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) { $this->db->addGban($uid, $this->userId, $this->t('sa.massban_reason')); $cnt++; }
                }
                self::send($this->chatId, $this->t('cmd.massban_done', ['n' => $cnt]));
                break;

            // ===== SUPERADMIN: CLEAR SEMUA GBAN =====
            case 'cleargban':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                if (!in_array($this->userId, SUPERADMINS)) { self::send($this->chatId, $this->t('error.owner_only')); break; }
                $cnt = $this->db->getGbanCount();
                $this->db->execute("DELETE FROM global_bans");
                self::send($this->chatId, $this->t('cmd.cleargban_done', ['n' => $cnt]));
                break;

            // ===== SUPERADMIN: ANNOUNCE (broadcast resmi) =====
            case 'announce':
                if (!self::isSA($this->userId)) { self::send($this->chatId, $this->t('error.access_denied')); break; }
                $btext = trim(implode(' ', $args));
                if (!$btext) { self::send($this->chatId, $this->t('sa.announce_usage')); break; }
                $groups = $this->db->getAllGroups();
                $ok = $fail = 0;
                $annText = $this->t('broadcast.announce', ['text' => $btext]);
                // Rate limit: 50ms delay antar grup + auto-retry jika 429
                foreach ($groups as $g) {
                    $r = self::broadcastSend('sendMessage', [
                        'chat_id'                  => (int)$g['chat_id'],
                        'text'                     => $annText,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                    ($r['ok'] ?? false) ? $ok++ : $fail++;
                }
                $this->db->execute("INSERT INTO broadcast_history (sent_by,message,success_count,fail_count) VALUES(?,?,?,?)", [$this->userId, $annText, $ok, $fail]);
                self::send($this->chatId, $this->t('broadcast.done', ['ok' => $ok, 'fail' => $fail]));
                break;

            case 'ai':
            case 'tanya':
            case 'ask': {
                $prompt = trim(implode(' ', $args));
                $replyCtx = '';
                if (!empty($msg['reply_to_message']['text'])) {
                    $replyCtx = $msg['reply_to_message']['text'];
                }
                if (!$prompt && !$replyCtx) {
                    self::send($this->chatId, $this->t('ai.usage'));
                    break;
                }
                $fullPrompt = $prompt;
                if ($replyCtx && $prompt) {
                    $fullPrompt = $this->t('ai.reply_context', ['ctx' => $replyCtx, 'prompt' => $prompt]);
                } elseif ($replyCtx) {
                    $fullPrompt = $this->t('ai.reply_only', ['ctx' => $replyCtx]);
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $history   = $this->db->getAiHistory($this->chatId, $this->userId);
                $chatModel = AiService::getActiveChatModel($this->db);
                $reply     = AiService::chat($fullPrompt, $history, $chatModel);
                if (strpos($reply, '❌') !== 0) {
                    $this->db->addAiMessage($this->chatId, $this->userId, 'user', $fullPrompt);
                    $this->db->addAiMessage($this->chatId, $this->userId, 'assistant', $reply);
                }
                sendAiReply($this->chatId, "🤖 " . $reply);
                break;
            }

            case 'resetai':
            case 'clearai': {
                $this->db->clearAiHistory($this->chatId, $this->userId);
                self::send($this->chatId, $this->t('ai.reset'));
                break;
            }

            case 'translate':
            case 'terjemahkan':
            case 'tr': {
                $targetLang = trim($args[0] ?? '');
                $textToTranslate = trim(implode(' ', array_slice($args, 1)));
                if (!$textToTranslate && !empty($msg['reply_to_message']['text'])) {
                    $textToTranslate = $msg['reply_to_message']['text'];
                }
                if (!$targetLang || !$textToTranslate) {
                    self::send($this->chatId, $this->t('translate.usage'));
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Translate the following text to {$targetLang}. Reply ONLY with the translated text, no explanation, no extra commentary:\n\n{$textToTranslate}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result);
                    break;
                }
                $reply = str_replace(['{lang}', '{result}'], [htmlspecialchars($targetLang), $result], $this->t('translate.result'));
                sendAiReply($this->chatId, $reply);
                break;
            }

            case 'detect':
            case 'deteksi': {
                $textToDetect = trim(implode(' ', $args));
                if (!$textToDetect && !empty($msg['reply_to_message']['text'])) {
                    $textToDetect = $msg['reply_to_message']['text'];
                }
                if (!$textToDetect) {
                    self::send($this->chatId, $this->t('detect.usage'));
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Detect the language of the following text. Reply with: the language name (in both English and the local name), the language code (ISO 639-1), and a short confidence note. Format:\nLanguage: [name in English] / [local name]\nCode: [ISO code]\nConfidence: [high/medium/low]\n\nText:\n{$textToDetect}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result);
                    break;
                }
                $reply = str_replace('{result}', $result, $this->t('detect.result'));
                sendAiReply($this->chatId, $reply);
                break;
            }

            case 'summarize':
            case 'ringkas':
            case 'tldr': {
                $textToSummarize = trim(implode(' ', $args));
                if (!$textToSummarize && !empty($msg['reply_to_message']['text'])) {
                    $textToSummarize = $msg['reply_to_message']['text'];
                }
                if (!$textToSummarize) {
                    self::send($this->chatId, $this->t('summarize.usage'));
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Summarize the following text concisely. Use the same language as the source text. Present key points clearly using bullet points if appropriate. Reply ONLY with the summary, no preamble:\n\n{$textToSummarize}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result);
                    break;
                }
                $reply = str_replace('{result}', $result, $this->t('summarize.result'));
                sendAiReply($this->chatId, $reply);
                break;
            }

            case 'imagine':
            case 'gambar':
            case 'buatgambar':
            case 'imgai': {
                $prompt = trim(implode(' ', $args));
                if (!$prompt) {
                    self::send($this->chatId, $this->t('img.usage'));
                    break;
                }

                // Cek limit harian per user (0 = tanpa batas)
                $dailyLimit = (int)$this->db->getBotSetting('img_daily_limit', '5');
                if ($dailyLimit > 0) {
                    $usedToday = $this->db->getImgUsageToday($this->userId);
                    if ($usedToday >= $dailyLimit) {
                        self::send($this->chatId, $this->t('img.limit_reached', [
                            'used'  => $usedToday,
                            'limit' => $dailyLimit,
                        ]));
                        break;
                    }
                }

                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'upload_photo']);
                $waitMsg   = self::send($this->chatId, $this->t('img.generating'));
                $waitMsgId = $waitMsg['result']['message_id'] ?? null;
                $imgModel  = AiService::getActiveImageModel($this->db);
                $result    = AiService::generateImage($prompt, $imgModel);
                if ($waitMsgId) {
                    self::api('deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $waitMsgId]);
                }
                if (!($result['ok'] ?? false)) {
                    self::send($this->chatId, $result['error'] ?? $this->t('img.failed'));
                    break;
                }
                $caption = Lang::t('img.caption', ['prompt' => htmlspecialchars(mb_substr($prompt, 0, 200))]);
                $this->db->incrementImgUsage($this->userId);
                self::sendPhoto(
                    $this->chatId,
                    $result['data'],
                    ['caption' => $caption, 'parse_mode' => 'HTML'],
                    $result['mime'] ?? 'image/png'
                );
                break;
            }

            case 'edit':
            case 'editgambar':
            case 'editimg': {
                $instruction = trim(implode(' ', $args));
                $curMsg      = $this->message;
                $repMsg      = $curMsg['reply_to_message'] ?? null;

                $hasPhotoInCurrent = !empty($curMsg['photo']) || !empty($curMsg['document']);
                $hasPhotoInReply   = !empty($repMsg) && (!empty($repMsg['photo']) || !empty($repMsg['document']));

                if (!$hasPhotoInCurrent && !$hasPhotoInReply) {
                    self::send($this->chatId, $this->t('img.edit_no_photo'));
                    break;
                }
                if (!$instruction) {
                    self::send($this->chatId, $this->t('img.edit_no_instruction'));
                    break;
                }

                $sourceMsg = $hasPhotoInCurrent ? $curMsg : $repMsg;
                $mimeHint  = '';
                if (!empty($sourceMsg['photo'])) {
                    $photoSizes = $sourceMsg['photo'];
                    $fileId     = end($photoSizes)['file_id'] ?? null;
                } elseif (!empty($sourceMsg['document'])) {
                    $mime = $sourceMsg['document']['mime_type'] ?? '';
                    if (strpos($mime, 'image/') !== 0) {
                        self::send($this->chatId, $this->t('img.edit_not_image'));
                        break;
                    }
                    $mimeHint = $mime;
                    $fileId   = $sourceMsg['document']['file_id'] ?? null;
                } else {
                    $fileId = null;
                }

                if (!$fileId) {
                    self::send($this->chatId, $this->t('img.edit_no_photo'));
                    break;
                }

                $fileInfo = self::api('getFile', ['file_id' => $fileId]);
                $filePath = $fileInfo['result']['file_path'] ?? null;
                if (!$filePath) {
                    self::send($this->chatId, $this->t('img.failed'));
                    break;
                }
                $fileUrl     = 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $filePath;
                $imgGen      = new AiImageGen();
                $imageBinary = $imgGen->downloadImage($fileUrl);
                if (!$imageBinary) {
                    self::send($this->chatId, $this->t('img.failed'));
                    break;
                }

                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'upload_photo']);
                $waitMsg   = self::send($this->chatId, $this->t('img.edit_generating'));
                $waitMsgId = $waitMsg['result']['message_id'] ?? null;

                $imgModel = AiService::getActiveImageModel($this->db);
                $result   = AiService::editImage($imageBinary, $instruction, $imgModel, $mimeHint);

                if ($waitMsgId) {
                    self::api('deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $waitMsgId]);
                }
                if (!($result['ok'] ?? false)) {
                    self::send($this->chatId, $result['error'] ?? $this->t('img.failed'));
                    break;
                }
                $editCaption = Lang::t('img.edit_caption', ['prompt' => htmlspecialchars(mb_substr($instruction, 0, 200))]);
                self::sendPhoto(
                    $this->chatId,
                    $result['data'],
                    ['caption' => $editCaption, 'parse_mode' => 'HTML'],
                    $result['mime'] ?? 'image/png'
                );
                break;
            }

            // ===== CODE RUNNER =====
            case 'run': {
                $fullText = $this->message['text'] ?? '';
                $parsed   = CodeRunner::parse($fullText);
                if (!$parsed) {
                    self::send($this->chatId, CodeRunner::helpText());
                    break;
                }
                [$langId, $langLabel, $code] = $parsed;
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $result  = CodeRunner::execute($langId, $code);
                $reply   = CodeRunner::format($langLabel, $result);
                self::send($this->chatId, $reply);
                break;
            }

            case 'setlang': {
                $curLang = $this->db->getBotSetting('user_lang_' . $this->userId, 'id');
                $txt = $this->t('lang.panel', ['current' => $this->t('lang.name_' . $curLang)]);
                self::send($this->chatId, $txt, ['reply_markup' => json_encode($this->kbLangPanel(0))]);
                break;
            }

            // ===== CRYPTO PRICE =====
            case 'price':
            case 'harga':
            case 'crypto': {
                if (empty($args[0])) {
                    self::send($this->chatId,
                        "📈 <b>Cek Harga Crypto</b>\n\n"
                        . "Gunakan:\n"
                        . "<code>/price &lt;koin&gt; [mata uang]</code>\n\n"
                        . "Contoh:\n"
                        . "• <code>/price btc</code> — BTC dalam USD\n"
                        . "• <code>/price btc idr</code> — BTC dalam IDR\n"
                        . "• <code>/price usdt to idr</code> — USDT ke IDR\n"
                        . "• <code>/price eth eur</code> — ETH dalam EUR\n\n"
                        . "Koin didukung: BTC, ETH, BNB, USDT, USDC, XRP, SOL, ADA, DOGE, DOT, MATIC, LTC, TRX, SHIB, AVAX, LINK, ATOM, UNI, TON, NEAR, FTM, PEPE, dll."
                    );
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                [$from, $to] = $this->parsePriceArgs($args);
                $result = $this->getCryptoPrice($from, $to);
                self::send($this->chatId, $this->formatPriceMsg($result));
                break;
            }

            default:
                if (!$cmd) self::send($this->chatId, $this->txtStart(), ['reply_markup' => json_encode($this->kbMain())]);
                break;
        }
    }

    // ================================================================
    //  GROUP COMMAND HANDLER
    // ================================================================

    private function handleGroupCmd(string $cmd, array $args, array $msg, array $settings, bool $isPriv, bool $isAdm): void {
        $msgId   = $msg['message_id'];
        $isSa    = self::isSA($this->userId);

        [$targetId, $targetMention, $reason, $args2] = $this->resolveTarget($msg, $args);

        switch ($cmd) {

            // ===== BLOKIR GAMBAR DI GRUP — hanya boleh di private chat =====
            case 'imagine':
            case 'gambar':
            case 'buatgambar':
            case 'imgai': {
                self::send($this->chatId, $this->t('img.private_only'));
                break;
            }

            case 'start':
            case 'help': {
                $cat = $this->resolveHelpCategory($args[0] ?? 'overview');
                self::send($this->chatId, $this->txtHelp($cat), ['reply_markup' => json_encode($this->kbHelp($cat, false))]);
                break;
            }
            case 'features':
            case 'fitur':
                self::send($this->chatId, $this->txtFeatures(), ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('start.btn_help'), 'callback_data' => 'menu_help'],
                     ['text' => $this->t('help.btn_close'), 'callback_data' => 'close_msg']],
                ]])]);
                break;

            case 'rules':
                $rules = $settings['rules'] ?: $this->t('rules.empty');
                if (!empty($settings['rules_btn_enabled'])) {
                    $kb = ['inline_keyboard' => [[
                        ['text' => $this->t('rules.agree_btn'), 'callback_data' => "rules_agree:{$this->userId}:{$this->chatId}"],
                    ]]];
                    self::send($this->chatId, $this->t('rules.title') . "\n\n$rules", ['reply_markup' => json_encode($kb)]);
                } else {
                    self::send($this->chatId, $this->t('rules.title') . "\n\n$rules");
                }
                break;

            case 'cekverif': {
                if (!$isAdm && !$isSa) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $query = $args[0] ?? '';
                if ($query) {
                    // Cek verifikasi user tertentu
                    $v = $this->db->getUserVerifications($query, $this->chatId);
                    if (!$v) {
                        self::send($this->chatId, "❌ Tidak ada data verifikasi untuk <code>" . htmlspecialchars($query) . "</code>.", ['parse_mode' => 'HTML']);
                        break;
                    }
                    $fn   = htmlspecialchars($v['first_name']);
                    $un   = $v['username'] ? "@{$v['username']}" : '<i>-</i>';
                    $time = date('d/m/Y H:i', (int)$v['verified_at']);
                    self::send($this->chatId,
                        "🔐 <b>Data Verifikasi</b>\n\n"
                        . "👤 <b>{$fn}</b> ({$un})\n"
                        . "🆔 <code>{$v['user_id']}</code>\n"
                        . "📱 <code>{$v['phone_number']}</code>\n"
                        . "📍 <a href='https://www.google.com/maps?q={$v['latitude']},{$v['longitude']}'>Lihat di Google Maps</a>\n"
                        . "🕒 {$time}",
                    ['parse_mode' => 'HTML']);
                } else {
                    // Tampilkan daftar verifikasi grup
                    $list = $this->db->getGroupVerifications($this->chatId, 30);
                    if (empty($list)) {
                        self::send($this->chatId, "📭 Belum ada data verifikasi anggota di grup ini.");
                        break;
                    }
                    $out = "🔐 <b>Data Verifikasi Anggota</b> (" . count($list) . ")\n\n";
                    foreach ($list as $v) {
                        $fn   = htmlspecialchars($v['first_name']);
                        $un   = $v['username'] ? "@{$v['username']}" : '-';
                        $time = date('d/m/Y H:i', (int)$v['verified_at']);
                        $out .= "• <b>{$fn}</b> ({$un}) — 📱 <code>{$v['phone_number']}</code> — 🕒 {$time}\n";
                    }
                    $out .= "\n<i>Gunakan /cekverif @username untuk detail lengkap.</i>";
                    self::send($this->chatId, $out, ['parse_mode' => 'HTML']);
                }
                break;
            }

            case 'me':
                $wc  = $this->db->getWarnCount($this->chatId, $this->userId);
                $vp  = $this->db->getViolationPoints($this->chatId, $this->userId);
                $mut = $this->db->fetchOne(
                    "SELECT * FROM mutes WHERE chat_id=? AND user_id=? AND (expires_at=0 OR expires_at>?)",
                    [$this->chatId, $this->userId, time()]
                );
                $gb  = $this->db->isGbanned($this->userId);
                $fn  = htmlspecialchars($this->userFullName);
                $un  = $msg['from']['username'] ?? '';
                $muteStatus = $mut
                    ? ($this->t('me.mute_active') . ($mut['expires_at'] ? $this->t('me.mute_until', ['time' => date('d/m/Y H:i', $mut['expires_at'])]) : $this->t('me.mute_perm')))
                    : $this->t('me.mute_no');
                $t = $this->t('me.title') . "\n\n"
                   . $this->t('me.name', ['name' => $fn]) . ($un ? $this->t('me.username', ['username' => $un]) : '') . "\n"
                   . $this->t('me.id', ['id' => $this->userId]) . "\n\n"
                   . $this->t('me.warns', ['count' => $wc, 'limit' => $settings['warn_limit']]) . "\n"
                   . $this->t('me.vp', ['points' => $vp, 'limit' => $settings['violation_limit']]) . "\n"
                   . $this->t('me.mute', ['status' => $muteStatus]) . "\n"
                   . $this->t('me.gban', ['status' => ($gb ? $this->t('me.gban_yes') : $this->t('me.gban_no'))]);
                self::send($this->chatId, $t);
                break;

            case 'id':
                $info  = $this->t('id.info', ['chat_id' => $this->chatId, 'user_id' => $this->userId]);
                if (!empty($msg['reply_to_message'])) {
                    $tid   = $msg['reply_to_message']['from']['id'] ?? '-';
                    $info .= "\n" . $this->t('id.target', ['id' => $tid]);
                }
                self::send($this->chatId, $info);
                break;

            case 'info':
            case 'whois':
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $m  = self::getChatMember($this->chatId, $targetId);
                $u  = $m['user'] ?? [];
                $fn = htmlspecialchars($u['first_name'] ?? '');
                $ln = htmlspecialchars($u['last_name']  ?? '');
                $un = $u['username'] ?? '';
                $wc = $this->db->getWarnCount($this->chatId, $targetId);
                $vp = $this->db->getViolationPoints($this->chatId, $targetId);
                $gb = $this->db->isGbanned($targetId) ? $this->t('me.gban_yes') : $this->t('me.gban_no');
                $t  = $this->t('info.title') . "\n" . $this->t('info.name', ['name' => trim("$fn $ln")]) . "\n";
                if ($un) $t .= $this->t('info.username', ['username' => $un]) . "\n";
                $t .= $this->t('info.id', ['id' => $u['id']]) . "\n"
                    . $this->t('info.status', ['status' => $m['status']]) . "\n"
                    . $this->t('info.warns', ['count' => $wc, 'limit' => $settings['warn_limit']]) . "\n"
                    . $this->t('info.vp', ['points' => $vp, 'limit' => $settings['violation_limit']]) . "\n"
                    . $this->t('info.gban', ['status' => $gb]);
                self::send($this->chatId, $t);
                break;

            case 'stats':
                $st  = $this->db->getStats($this->chatId);
                $cnt = self::getMemberCount($this->chatId);
                $t   = $this->t('stats.title')
                     . $this->t('stats.members', ['n' => $cnt])
                     . $this->t('stats.messages', ['n' => $st['total_messages']])
                     . $this->t('stats.banned', ['n' => $st['total_banned']])
                     . $this->t('stats.warned', ['n' => $st['total_warned']])
                     . $this->t('stats.muted', ['n' => $st['total_muted']]);
                self::send($this->chatId, $t);
                break;

            case 'adminlist':
                $adms = self::api('getChatAdministrators', ['chat_id' => $this->chatId]);
                if (!($adms['ok'] ?? false)) { self::send($this->chatId, $this->t('error.fetch_failed')); break; }
                $t = $this->t('adminlist.title') . "\n\n";
                foreach ($adms['result'] as $a) {
                    $u   = $a['user'];
                    $fn  = htmlspecialchars($u['first_name'] ?? '');
                    $un  = isset($u['username']) ? "@{$u['username']}" : "<code>{$u['id']}</code>";
                    $ico = $a['status'] === 'creator' ? '👑' : '⭐';
                    $ct  = $a['custom_title'] ?? '';
                    $t  .= "$ico $fn" . ($ct ? " [$ct]" : '') . " ($un)\n";
                }
                self::send($this->chatId, $t);
                break;

            // ===== BAN / KICK / MUTE =====
            case 'ban':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) {
                    $targetMention ? $this->sendNoTargetError($targetId, $targetMention) : self::send($this->chatId, $this->t('ban.usage'));
                    break;
                }
                if (self::isAdmin($this->chatId, $targetId)) { self::send($this->chatId, $this->t('error.cant_ban_admin')); break; }
                if (self::ban($this->chatId, $targetId)) {
                    $this->db->execute("INSERT OR REPLACE INTO bans (chat_id,user_id,banned_by,reason) VALUES(?,?,?,?)", [$this->chatId, $targetId, $this->userId, $reason]);
                    $this->db->incrementStats($this->chatId, 'total_banned');
                    self::send($this->chatId, $this->t('mod.banned', ['mention' => $targetMention, 'reason' => $reason]));
                }
                break;

            case 'tban':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) {
                    $targetMention ? $this->sendNoTargetError($targetId, $targetMention) : self::send($this->chatId, $this->t('cmd.tban_usage'));
                    break;
                }
                $durText = $args2[0] ?? '';
                $until   = $this->parseDuration($durText);
                if (!$until) { self::send($this->chatId, $this->t('cmd.tban_dur_err')); break; }
                $r2      = implode(' ', array_slice($args2, 1)) ?: $reason;
                if (self::ban($this->chatId, $targetId, $until)) {
                    $this->db->incrementStats($this->chatId, 'total_banned');
                    self::send($this->chatId, $this->t('mod.ban_temp', ['mention' => $targetMention, 'duration' => $durText, 'reason' => $r2]));
                }
                break;

            case 'unban':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { self::send($this->chatId, $this->t('error.mention_user')); break; }
                if (self::unban($this->chatId, $targetId)) {
                    $this->db->execute("DELETE FROM bans WHERE chat_id=? AND user_id=?", [$this->chatId, $targetId]);
                    self::send($this->chatId, $this->t('mod.unbanned', ['mention' => $targetMention]));
                }
                break;

            case 'kick':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) {
                    $targetMention ? $this->sendNoTargetError($targetId, $targetMention) : self::send($this->chatId, $this->t('kick.usage'));
                    break;
                }
                if (self::isAdmin($this->chatId, $targetId)) { self::send($this->chatId, $this->t('error.cant_kick_admin')); break; }
                if (self::kick($this->chatId, $targetId)) {
                    self::send($this->chatId, $this->t('mod.kicked', ['mention' => $targetMention, 'reason' => $reason]));
                }
                break;

            case 'mute':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if (self::isAdmin($this->chatId, $targetId)) { self::send($this->chatId, $this->t('error.cant_mute_admin')); break; }
                $durStr  = $args2[0] ?? '';
                $until   = $this->parseDuration($durStr);
                $durDisp = $until ? $durStr : $this->t('mod.perm');
                $r2      = $until ? (implode(' ', array_slice($args2, 1)) ?: $this->t('error.no_reason')) : $reason;
                if (self::mute($this->chatId, $targetId, $until)) {
                    $this->db->execute("INSERT OR REPLACE INTO mutes (chat_id,user_id,muted_by,reason,expires_at) VALUES(?,?,?,?,?)", [$this->chatId, $targetId, $this->userId, $r2, $until]);
                    $this->db->incrementStats($this->chatId, 'total_muted');
                    self::send($this->chatId, $this->t('mod.muted', ['mention' => $targetMention, 'duration' => $durDisp, 'reason' => $r2]));
                }
                break;

            case 'unmute':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if (self::unmute($this->chatId, $targetId)) {
                    $this->db->execute("DELETE FROM mutes WHERE chat_id=? AND user_id=?", [$this->chatId, $targetId]);
                    self::send($this->chatId, $this->t('mod.unmuted', ['mention' => $targetMention]));
                }
                break;

            // ===== WARN =====
            case 'warn':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if (self::isAdmin($this->chatId, $targetId)) { self::send($this->chatId, $this->t('error.cant_warn_admin')); break; }
                $wc    = $this->db->addWarn($this->chatId, $targetId, $this->userId, $reason);
                $wlim  = (int)$settings['warn_limit'];
                $this->db->incrementStats($this->chatId, 'total_warned');
                $t     = $this->t('mod.warned', ['mention' => $targetMention, 'count' => $wc, 'limit' => $wlim, 'reason' => $reason]);
                if ($wc >= $wlim) {
                    $act = $settings['warn_action'];
                    $t  .= $this->t('mod.warn_limit', ['action' => $act]);
                    if ($act === 'ban')  { self::ban($this->chatId, $targetId);  $this->db->clearWarns($this->chatId, $targetId); $this->db->incrementStats($this->chatId, 'total_banned'); }
                    if ($act === 'kick') { self::kick($this->chatId, $targetId); $this->db->clearWarns($this->chatId, $targetId); }
                    if ($act === 'mute') { self::mute($this->chatId, $targetId); $this->db->clearWarns($this->chatId, $targetId); }
                }
                self::send($this->chatId, $t);
                break;

            case 'unwarn':
            case 'rmwarn':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $nw = $this->db->removeWarn($this->chatId, $targetId);
                self::send($this->chatId, $this->t('mod.unwarn', ['mention' => $targetMention, 'count' => $nw, 'limit' => $settings['warn_limit']]));
                break;

            case 'warns':
                $tid  = $targetId ?: $this->userId;
                $tmn  = $targetId ? $targetMention : $this->userMention;
                $wc   = $this->db->getWarnCount($this->chatId, $tid);
                $list = $this->db->getWarnList($this->chatId, $tid);
                $t    = $this->t('warn.list_header', ['mention' => $tmn, 'count' => $wc, 'limit' => $settings['warn_limit']]) . "\n";
                foreach ($list as $i => $w) $t .= "\n" . ($i+1) . ". {$w['reason']} — " . date('d/m/Y H:i', $w['warned_at']);
                self::send($this->chatId, $t);
                break;

            case 'clearwarns':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $this->db->clearWarns($this->chatId, $targetId);
                self::send($this->chatId, $this->t('mod.clearwarns', ['mention' => $targetMention]));
                break;

            // ===== POIN SANKSI =====
            case 'vpoints':
            case 'poin':
                $tid  = $targetId ?: $this->userId;
                $tmn  = $targetId ? $targetMention : $this->userMention;
                $vp   = $this->db->getViolationPoints($this->chatId, $tid);
                $vlim = (int)($settings['violation_limit'] ?? 5);
                $von  = $settings['violation_enabled'] ?? 0;
                $t    = $this->t('vp.display', [
                    'mention' => $tmn,
                    'points'  => $vp,
                    'limit'   => $vlim,
                    'status'  => ($von ? '✅ ' . $this->t('vp.status_on') : '❌ ' . $this->t('vp.status_off')),
                ]);
                self::send($this->chatId, $t);
                break;

            case 'resetvp':
            case 'resetpoin':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $this->db->resetViolationPoints($this->chatId, $targetId);
                self::send($this->chatId, $this->t('vp.reset_done', ['mention' => $targetMention]));
                break;

            case 'topviolators':
            case 'violators':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $list = $this->db->getViolationLeaderboard($this->chatId, 10);
                if (empty($list)) { self::send($this->chatId, $this->t('vp.no_violators')); break; }
                $t = $this->t('vp.top_title');
                foreach ($list as $i => $row) {
                    $name = $row['username'] ? '@' . $row['username'] : htmlspecialchars($row['first_name'] ?: "User #{$row['user_id']}");
                    $t .= ($i + 1) . ". $name — <b>{$row['points']}</b>\n";
                }
                self::send($this->chatId, $t);
                break;

            // ===== SANG MATA: RIWAYAT USERNAME =====
            case 'sangmata':
            case 'usernamehistory':
            case 'cekun': {
                $tid = $targetId;
                if (!$tid) {
                    // Coba dari argumen (ID langsung)
                    if (!empty($args[0]) && is_numeric($args[0])) {
                        $tid = (int)$args[0];
                    }
                }
                if (!$tid) {
                    self::send($this->chatId, $this->t('sang_mata.no_target'));
                    break;
                }
                $history = $this->db->getUsernameHistory($tid, 15);
                if (empty($history)) {
                    $uc  = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [$tid]);
                    $name = $uc ? htmlspecialchars(trim(($uc['first_name'] ?? '') . ' ' . ($uc['last_name'] ?? ''))) : "User #$tid";
                    self::send($this->chatId, $this->t('sang_mata.no_history', ['name' => $name, 'uid' => $tid]));
                    break;
                }
                $uc   = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [$tid]);
                $name = $uc ? htmlspecialchars(trim(($uc['first_name'] ?? '') . ' ' . ($uc['last_name'] ?? ''))) : "User #$tid";
                $t    = $this->t('sang_mata.history_title', ['name' => $name, 'uid' => $tid]);
                foreach ($history as $i => $row) {
                    $oldUn = $row['old_username'] ? '@' . $row['old_username'] : '<i>' . $this->t('sang_mata.no_username') . '</i>';
                    $newUn = $row['new_username'] ? '@' . $row['new_username'] : '<i>' . $this->t('sang_mata.no_username') . '</i>';
                    $date  = date('d/m/Y H:i', (int)$row['changed_at']);
                    $t    .= ($i + 1) . ". $oldUn → $newUn\n   <code>$date</code>\n";
                }
                self::send($this->chatId, $t);
                break;
            }

            // ===== VOTE KICK =====
            case 'votekick':
            case 'vkick': {
                if ($this->chatId > 0) { self::send($this->chatId, $this->t('error.group_only')); break; }
                if (!$settings['votekick_enabled']) { self::send($this->chatId, $this->t('vk.no_feature')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if ($targetId === $this->userId) { self::send($this->chatId, $this->t('vk.self_vote')); break; }
                if (self::isAdmin($this->chatId, $targetId)) { self::send($this->chatId, $this->t('vk.cant_vk_admin')); break; }
                // Cek admin_only: hanya admin yang boleh mulai votekick
                if (!empty($settings['votekick_admin_only']) && !$isAdm && !$isSa) {
                    self::send($this->chatId, $this->t('vk.admin_only_err'));
                    break;
                }
                // Cek cooldown per user
                $cooldownMin = (int)($settings['votekick_cooldown'] ?? 0);
                if ($cooldownMin > 0 && !$isSa) {
                    $remaining = $this->db->getVotekickCooldownRemaining($this->chatId, $this->userId, $cooldownMin * 60);
                    if ($remaining > 0) {
                        $minLeft = (int)ceil($remaining / 60);
                        self::send($this->chatId, $this->t('vk.cooldown_err', ['min' => $minLeft]));
                        break;
                    }
                }
                $existing = $this->db->getActiveVotekickByTarget($this->chatId, $targetId);
                if ($existing) {
                    self::send($this->chatId, $this->t('vk.session_exists', ['id' => $existing['id']]));
                    break;
                }
                // Ambil nama target dari reply atau user_cache
                $vkTargetName = '';
                if (!empty($msg['reply_to_message']['from'])) {
                    $rf = $msg['reply_to_message']['from'];
                    $vkTargetName = trim(($rf['first_name'] ?? '') . ' ' . ($rf['last_name'] ?? ''));
                }
                if (!$vkTargetName) {
                    $uc = $this->db->fetchOne("SELECT first_name, last_name, username FROM user_cache WHERE user_id = ?", [$targetId]);
                    if ($uc) $vkTargetName = trim(($uc['first_name'] ?? '') . ' ' . ($uc['last_name'] ?? ''));
                    if (!$vkTargetName && !empty($uc['username'])) $vkTargetName = '@' . $uc['username'];
                }
                if (!$vkTargetName) $vkTargetName = "User #$targetId";

                // Hitung threshold: mode absolut atau persentase member
                $vkMode = (int)($settings['votekick_mode'] ?? 0);
                if ($vkMode === 1) {
                    $pct         = (int)($settings['votekick_percent'] ?? 10);
                    $memberCount = (int)(self::api('getChatMemberCount', ['chat_id' => $this->chatId])['result'] ?? 0);
                    $threshold   = max(5, (int)ceil($memberCount * $pct / 100));
                } else {
                    $threshold = (int)($settings['votekick_threshold'] ?? 5);
                }
                $durMinutes = (int)($settings['votekick_duration'] ?? 5);
                $expiresAt  = time() + ($durMinutes * 60);
                $sessionId  = $this->db->createVotekickSession(
                    $this->chatId, $targetId, $vkTargetName,
                    $this->userId, $reason, $threshold, $expiresAt
                );
                $session = [
                    'id'           => $sessionId,
                    'chat_id'      => $this->chatId,
                    'target_id'    => $targetId,
                    'target_name'  => $vkTargetName,
                    'initiator_id' => $this->userId,
                    'reason'       => $reason,
                    'yes_count'    => 0,
                    'no_count'     => 0,
                    'threshold'    => $threshold,
                    'expires_at'   => $expiresAt,
                    'status'       => 'active',
                ];
                $sent = self::api('sendMessage', [
                    'chat_id'      => $this->chatId,
                    'text'         => $this->buildVotekickMsg($session, $durMinutes),
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode($this->buildVotekickKb($session)),
                ]);
                if ($sent['ok'] ?? false) {
                    $this->db->updateVotekickMessageId($sessionId, (int)$sent['result']['message_id']);
                }
                break;
            }

            case 'cancelvotekick':
            case 'cancelvo': {
                if ($this->chatId > 0) { self::send($this->chatId, $this->t('vk.not_group_only')); break; }
                if (!$targetId) { self::send($this->chatId, $this->t('vk.cancel_no_target')); break; }
                $existing = $this->db->getActiveVotekickByTarget($this->chatId, $targetId);
                if (!$existing) { self::send($this->chatId, $this->t('vk.no_session')); break; }
                if ((int)$existing['initiator_id'] !== $this->userId && !$isAdm && !$isSa) {
                    self::send($this->chatId, $this->t('vk.cancel_no_perm'));
                    break;
                }
                $this->db->updateVotekickStatus((int)$existing['id'], 'cancelled');
                if ($existing['message_id']) {
                    self::edit($this->chatId, (int)$existing['message_id'],
                        $this->t('vk.cancelled_msg', ['name' => htmlspecialchars($existing['target_name']), 'by' => $this->userMention]));
                }
                self::send($this->chatId, $this->t('vk.cancelled_reply', ['name' => htmlspecialchars($existing['target_name'])]));
                break;
            }

            // ===== AI =====
            case 'ai':
            case 'tanya':
            case 'ask': {
                if (!$settings['ai_enabled']) {
                    self::send($this->chatId, $this->t('ai.disabled'));
                    break;
                }
                $prompt = trim(implode(' ', $args));
                $replyCtx = '';
                if (!empty($msg['reply_to_message']['text'])) {
                    $replyCtx = $msg['reply_to_message']['text'];
                }
                if (!$prompt && !$replyCtx) {
                    self::send($this->chatId, $this->t('ai.usage'));
                    break;
                }
                $fullPrompt = $prompt;
                if ($replyCtx && $prompt) {
                    $fullPrompt = $this->t('ai.reply_context', ['ctx' => $replyCtx, 'prompt' => $prompt]);
                } elseif ($replyCtx) {
                    $fullPrompt = $this->t('ai.reply_only', ['ctx' => $replyCtx]);
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $history   = $this->db->getAiHistory($this->chatId, $this->userId);
                $chatModel = AiService::getActiveChatModel($this->db);
                $aiReply   = AiService::chat($fullPrompt, $history, $chatModel);
                if (strpos($aiReply, '❌') !== 0) {
                    $this->db->addAiMessage($this->chatId, $this->userId, 'user', $fullPrompt);
                    $this->db->addAiMessage($this->chatId, $this->userId, 'assistant', $aiReply);
                }
                sendAiReply($this->chatId, "🤖 " . $aiReply, ['reply_to_message_id' => $msgId]);
                break;
            }

            case 'resetai':
            case 'clearai': {
                $this->db->clearAiHistory($this->chatId, $this->userId);
                self::send($this->chatId, $this->t('ai.reset'));
                break;
            }

            case 'translate':
            case 'terjemahkan':
            case 'tr': {
                if (!$settings['ai_enabled']) {
                    self::send($this->chatId, $this->t('translate.disabled'));
                    break;
                }
                $targetLang = trim($args[0] ?? '');
                $textToTranslate = trim(implode(' ', array_slice($args, 1)));
                if (!$textToTranslate && !empty($msg['reply_to_message']['text'])) {
                    $textToTranslate = $msg['reply_to_message']['text'];
                }
                if (!$targetLang || !$textToTranslate) {
                    self::send($this->chatId, $this->t('translate.usage'), ['reply_to_message_id' => $msgId]);
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Translate the following text to {$targetLang}. Reply ONLY with the translated text, no explanation, no extra commentary:\n\n{$textToTranslate}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result, ['reply_to_message_id' => $msgId]);
                    break;
                }
                $reply = str_replace(['{lang}', '{result}'], [htmlspecialchars($targetLang), $result], $this->t('translate.result'));
                sendAiReply($this->chatId, $reply, ['reply_to_message_id' => $msgId]);
                break;
            }

            case 'detect':
            case 'deteksi': {
                if (!$settings['ai_enabled']) {
                    self::send($this->chatId, $this->t('detect.disabled'));
                    break;
                }
                $textToDetect = trim(implode(' ', $args));
                if (!$textToDetect && !empty($msg['reply_to_message']['text'])) {
                    $textToDetect = $msg['reply_to_message']['text'];
                }
                if (!$textToDetect) {
                    self::send($this->chatId, $this->t('detect.usage'), ['reply_to_message_id' => $msgId]);
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Detect the language of the following text. Reply with: the language name (in both English and the local name), the language code (ISO 639-1), and a short confidence note. Format:\nLanguage: [name in English] / [local name]\nCode: [ISO code]\nConfidence: [high/medium/low]\n\nText:\n{$textToDetect}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result, ['reply_to_message_id' => $msgId]);
                    break;
                }
                $reply = str_replace('{result}', $result, $this->t('detect.result'));
                sendAiReply($this->chatId, $reply, ['reply_to_message_id' => $msgId]);
                break;
            }

            case 'summarize':
            case 'ringkas':
            case 'tldr': {
                if (!$settings['ai_enabled']) {
                    self::send($this->chatId, $this->t('summarize.disabled'));
                    break;
                }
                $textToSummarize = trim(implode(' ', $args));
                if (!$textToSummarize && !empty($msg['reply_to_message']['text'])) {
                    $textToSummarize = $msg['reply_to_message']['text'];
                }
                if (!$textToSummarize) {
                    self::send($this->chatId, $this->t('summarize.usage'), ['reply_to_message_id' => $msgId]);
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $prompt    = "Summarize the following text concisely. Use the same language as the source text. Present key points clearly using bullet points if appropriate. Reply ONLY with the summary, no preamble:\n\n{$textToSummarize}";
                $chatModel = AiService::getActiveChatModel($this->db);
                $result    = AiService::chat($prompt, [], $chatModel);
                if (strpos($result, '❌') === 0) {
                    self::send($this->chatId, $result, ['reply_to_message_id' => $msgId]);
                    break;
                }
                $reply = str_replace('{result}', $result, $this->t('summarize.result'));
                sendAiReply($this->chatId, $reply, ['reply_to_message_id' => $msgId]);
                break;
            }

            // ===== CODE RUNNER =====
            case 'run': {
                $fullText = $msg['text'] ?? '';
                $parsed   = CodeRunner::parse($fullText);
                if (!$parsed) {
                    self::send($this->chatId, CodeRunner::helpText(), ['reply_to_message_id' => $msgId]);
                    break;
                }
                [$langId, $langLabel, $code] = $parsed;
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                $result = CodeRunner::execute($langId, $code);
                $reply  = CodeRunner::format($langLabel, $result);
                self::send($this->chatId, $reply, ['reply_to_message_id' => $msgId]);
                break;
            }

            // ===== CRYPTO PRICE =====
            case 'price':
            case 'harga':
            case 'crypto': {
                if (empty($args[0])) {
                    self::send($this->chatId,
                        "📈 <b>Cek Harga Crypto</b>\n\n"
                        . "Gunakan:\n"
                        . "<code>/price &lt;koin&gt; [mata uang]</code>\n\n"
                        . "Contoh:\n"
                        . "• <code>/price btc</code> — BTC dalam USD\n"
                        . "• <code>/price btc idr</code> — BTC dalam IDR\n"
                        . "• <code>/price usdt to idr</code> — USDT ke IDR\n"
                        . "• <code>/price eth eur</code> — ETH dalam EUR\n\n"
                        . "Koin didukung: BTC, ETH, BNB, USDT, USDC, XRP, SOL, ADA, DOGE, DOT, MATIC, LTC, TRX, SHIB, AVAX, LINK, ATOM, UNI, TON, NEAR, FTM, PEPE, dll.",
                        ['reply_to_message_id' => $msgId]
                    );
                    break;
                }
                self::api('sendChatAction', ['chat_id' => $this->chatId, 'action' => 'typing']);
                [$from, $to] = $this->parsePriceArgs($args);
                $priceResult = $this->getCryptoPrice($from, $to);
                self::send($this->chatId, $this->formatPriceMsg($priceResult), ['reply_to_message_id' => $msgId]);
                break;
            }

            // ===== PROMOTE / DEMOTE =====
            case 'promote':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if (self::promote($this->chatId, $targetId)) {
                    self::clearAdminCache($this->chatId);
                    self::send($this->chatId, $this->t('mod.promoted', ['mention' => $targetMention]));
                }
                break;

            case 'demote':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                if (self::demote($this->chatId, $targetId)) {
                    self::clearAdminCache($this->chatId);
                    $this->db->removeAdminGroup($targetId, $this->chatId);
                    self::send($this->chatId, $this->t('mod.demoted', ['mention' => $targetMention]));
                }
                break;

            case 'settitle':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (!$targetId || empty($args2)) { self::send($this->chatId, $this->t('settitle.usage')); break; }
                $title  = implode(' ', $args2);
                $result = self::api('setChatAdministratorCustomTitle', ['chat_id' => $this->chatId, 'user_id' => $targetId, 'custom_title' => substr($title, 0, 16)]);
                if (!empty($result['ok'])) {
                    self::send($this->chatId, $this->t('settitle.set', ['mention' => $targetMention, 'title' => htmlspecialchars($title)]));
                } else {
                    self::send($this->chatId, $this->t('settitle.failed'));
                }
                break;

            // ===== PIN =====
            case 'pin':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($msg['reply_to_message'])) { self::send($this->chatId, $this->t('pin.usage')); break; }
                $pinId  = $msg['reply_to_message']['message_id'];
                $notify = in_array(strtolower($args[0] ?? ''), ['notify', 'loud']);
                self::api('pinChatMessage', ['chat_id' => $this->chatId, 'message_id' => $pinId, 'disable_notification' => !$notify]);
                self::send($this->chatId, $this->t('mod.pinned'));
                break;

            case 'unpin':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $pinMsgId = !empty($msg['reply_to_message']) ? $msg['reply_to_message']['message_id'] : null;
                self::api('unpinChatMessage', array_filter(['chat_id' => $this->chatId, 'message_id' => $pinMsgId]));
                self::send($this->chatId, $this->t('mod.unpinned'));
                break;

            case 'unpinall':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                self::api('unpinAllChatMessages', ['chat_id' => $this->chatId]);
                self::send($this->chatId, $this->t('mod.unpinall'));
                break;

            // ===== DELETE / PURGE =====
            case 'del':
            case 'delete':
                if (!$isPriv) break;
                if (!empty($msg['reply_to_message'])) self::del($this->chatId, $msg['reply_to_message']['message_id']);
                self::del($this->chatId, $msgId);
                break;

            case 'purge': {
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }

                // ── Mode 1: /purge @username — hapus semua pesan dari user tertentu ──
                if (empty($msg['reply_to_message']) && $targetId) {
                    $userMsgIds = $this->db->getLoggedMessageIdsByUser($this->chatId, $targetId, 1000);
                    if (empty($userMsgIds)) {
                        self::send($this->chatId, str_replace('{mention}', $targetMention, $this->t('purge.user_not_found')), ['reply_to_message_id' => $msgId]);
                        self::del($this->chatId, $msgId);
                        break;
                    }
                    // Tambahkan pesan /purge itu sendiri agar ikut terhapus
                    $userMsgIds[] = $msgId;
                    $userMsgIds   = array_values(array_unique($userMsgIds));
                    $attempted    = count($userMsgIds);
                    foreach (array_chunk($userMsgIds, 100) as $batch) {
                        // deleteMessages: maks 100 ID per call per Bot API docs
                        // @see https://core.telegram.org/bots/api#deletemessages
                        self::api('deleteMessages', ['chat_id' => $this->chatId, 'message_ids' => $batch]);
                        usleep(300000); // 300ms antar batch untuk hindari flood delete
                    }
                    $doneText = str_replace(['{n}', '{mention}'], [$attempted, $targetMention], $this->t('purge.user_done'));
                    $n = self::send($this->chatId, $doneText);
                    sleep(3);
                    if (!empty($n['result']['message_id'])) self::del($this->chatId, $n['result']['message_id']);
                    break;
                }

                // ── Mode 2: reply ke pesan → hapus dari situ sampai sekarang ──
                if (empty($msg['reply_to_message'])) {
                    self::send($this->chatId, $this->t('purge.user_usage'));
                    break;
                }
                $purgeFrom = (int)$msg['reply_to_message']['message_id'];
                $purgeTo   = $msgId;

                $loggedIds = $this->db->getLoggedMessageIds($this->chatId, 4000);
                if (!empty($loggedIds)) {
                    $purgeIds = array_values(array_filter($loggedIds, fn($id) => $id >= $purgeFrom && $id <= $purgeTo));
                    if (!in_array($purgeTo, $purgeIds, true)) $purgeIds[] = $purgeTo;
                } else {
                    $purgeIds = range($purgeFrom, $purgeTo);
                    if (count($purgeIds) > 200) $purgeIds = array_slice($purgeIds, 0, 200);
                }

                $attempted = count($purgeIds);
                foreach (array_chunk($purgeIds, 100) as $purgeBatch) {
                    // deleteMessages: maks 100 ID per call per Bot API docs
                    // @see https://core.telegram.org/bots/api#deletemessages
                    self::api('deleteMessages', ['chat_id' => $this->chatId, 'message_ids' => $purgeBatch]);
                    usleep(300000); // 300ms antar batch untuk hindari flood delete
                }
                $n = self::send($this->chatId, $this->t('purge.done', ['n' => $attempted]));
                sleep(2);
                if (!empty($n['result']['message_id'])) self::del($this->chatId, $n['result']['message_id']);
                break;
            }

            // ===== LOCK / UNLOCK =====
            case 'lock':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $ltype = strtolower($args[0] ?? '');
                $map   = ['sticker' => 'lock_sticker', 'gif' => 'lock_gif', 'media' => 'lock_media', 'voice' => 'lock_voice', 'game' => 'lock_game', 'poll' => 'lock_poll', 'forward' => 'anti_forward'];
                if (!$ltype || !isset($map[$ltype])) {
                    self::send($this->chatId, $this->t('lock.usage')); break;
                }
                $this->db->updateGroupSetting($this->chatId, $map[$ltype], 1);
                self::send($this->chatId, $this->t('lock.locked', ['type' => $ltype]));
                break;

            case 'unlock':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $ltype = strtolower($args[0] ?? '');
                $map   = ['sticker' => 'lock_sticker', 'gif' => 'lock_gif', 'media' => 'lock_media', 'voice' => 'lock_voice', 'game' => 'lock_game', 'poll' => 'lock_poll', 'forward' => 'anti_forward'];
                if (!$ltype || !isset($map[$ltype])) {
                    self::send($this->chatId, $this->t('lock.usage')); break;
                }
                $this->db->updateGroupSetting($this->chatId, $map[$ltype], 0);
                self::send($this->chatId, $this->t('lock.unlocked', ['type' => $ltype]));
                break;

            case 'locks':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $on = fn($v) => $v ? '🔒' : '🔓';
                $t  = $this->t('settings.locks_title') . "\n\n";
                $t .= $on($settings['lock_sticker']) . " Sticker\n";
                $t .= $on($settings['lock_gif'])     . " GIF\n";
                $t .= $on($settings['lock_media'])   . " Media\n";
                $t .= $on($settings['lock_voice'])   . " Voice\n";
                $t .= $on($settings['lock_game'])    . " Game\n";
                $t .= $on($settings['lock_poll'])    . " Poll\n";
                $t .= $on($settings['anti_forward']) . " Forward\n";
                self::send($this->chatId, $t);
                break;

            // ===== SLOWMODE =====
            case 'slowmode':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $sec = (int)($args[0] ?? 0);
                if ($sec < 0 || $sec > 86400) { self::send($this->chatId, $this->t('slowmode.usage')); break; }
                if (self::setSlowmode($this->chatId, $sec)) {
                    self::send($this->chatId, $sec === 0 ? $this->t('slowmode.off') : $this->t('slowmode.set', ['sec' => $sec]));
                }
                break;

            // ===== NOTE SYSTEM =====
            case 'save':
            case 'note':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('note.save_usage')); break; }
                $keyword  = strtolower($args[0]);
                $content  = implode(' ', array_slice($args, 1));
                $fileId   = '';
                $fileType = '';
                if (!empty($msg['reply_to_message'])) {
                    $rep = $msg['reply_to_message'];
                    if (empty($content)) $content = $rep['text'] ?? $rep['caption'] ?? '';
                    if (!empty($rep['photo']))    { $last = end($rep['photo']); $fileId = $last['file_id']; $fileType = 'photo'; }
                    elseif (!empty($rep['video']))    { $fileId = $rep['video']['file_id'];    $fileType = 'video'; }
                    elseif (!empty($rep['document'])) { $fileId = $rep['document']['file_id']; $fileType = 'document'; }
                    elseif (!empty($rep['audio']))    { $fileId = $rep['audio']['file_id'];    $fileType = 'audio'; }
                    elseif (!empty($rep['sticker']))  { $fileId = $rep['sticker']['file_id'];  $fileType = 'sticker'; }
                }
                if (empty($content) && empty($fileId)) { self::send($this->chatId, $this->t('note.empty_content')); break; }
                $this->db->saveNote($this->chatId, $keyword, $content, $fileId, $fileType, $this->userId);
                self::send($this->chatId, $this->t('note.saved', ['keyword' => $keyword]));
                break;

            case 'get':
                if (empty($args[0])) { self::send($this->chatId, $this->t('note.get_usage')); break; }
                $note = $this->db->getNote($this->chatId, strtolower($args[0]));
                if (!$note) { self::send($this->chatId, $this->t('note.not_found', ['keyword' => htmlspecialchars($args[0])])); break; }
                $this->sendNote($note);
                break;

            case 'notes':
                $notes = $this->db->getNotes($this->chatId);
                if (empty($notes)) { self::send($this->chatId, $this->t('note.list_empty')); break; }
                $list = implode(', ', array_map(fn($n) => "#" . $n['keyword'], $notes));
                self::send($this->chatId, $this->t('note.list_header', ['n' => count($notes)]) . $list);
                break;

            case 'clear':
            case 'clearnote':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('note.usage_clear')); break; }
                $this->db->deleteNote($this->chatId, strtolower($args[0]));
                self::send($this->chatId, $this->t('note.deleted', ['keyword' => htmlspecialchars($args[0])]));
                break;

            // ===== WORD FILTER =====
            case 'banword':
            case 'addword':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('bw.usage')); break; }
                $word = strtolower(trim($args[0]));
                $bw   = json_decode($settings['banned_words'] ?? '[]', true) ?: [];
                if (in_array($word, $bw)) { self::send($this->chatId, $this->t('bw.exists')); break; }
                $bw[] = $word;
                $this->db->updateGroupSetting($this->chatId, 'banned_words', json_encode($bw));
                $this->db->updateGroupSetting($this->chatId, 'word_filter_enabled', 1);
                self::send($this->chatId, $this->t('bw.added', ['word' => $word]));
                break;

            case 'unbanword':
            case 'rmword':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('bw.usage_rm')); break; }
                $word = strtolower(trim($args[0]));
                $bw   = json_decode($settings['banned_words'] ?? '[]', true) ?: [];
                $bw   = array_values(array_filter($bw, fn($w) => $w !== $word));
                $this->db->updateGroupSetting($this->chatId, 'banned_words', json_encode($bw));
                self::send($this->chatId, $this->t('bw.removed', ['word' => $word]));
                break;

            case 'banwords':
                $bw = json_decode($settings['banned_words'] ?? '[]', true) ?: [];
                if (empty($bw)) { self::send($this->chatId, $this->t('bw.empty')); break; }
                self::send($this->chatId, $this->t('bw.list', ['count' => count($bw), 'list' => implode(', ', array_map('htmlspecialchars', $bw))]));
                break;

            // ===== FILTER =====
            case 'filter':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (count($args) < 2) { self::send($this->chatId, $this->t('filter.usage_add')); break; }
                $kw  = strtolower(array_shift($args));
                $rsp = implode(' ', $args);
                $this->db->execute("INSERT OR REPLACE INTO saved_filters (chat_id,keyword,response,added_by) VALUES(?,?,?,?)", [$this->chatId, $kw, $rsp, $this->userId]);
                self::send($this->chatId, $this->t('filter.added', ['keyword' => $kw]));
                break;

            case 'filters':
                $fs = $this->db->getFilters($this->chatId);
                if (empty($fs)) { self::send($this->chatId, $this->t('filter.empty')); break; }
                $list = implode(', ', array_column($fs, 'keyword'));
                self::send($this->chatId, $this->t('filter.list', ['list' => $list]));
                break;

            case 'stop':
            case 'rmfilter':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('filter.usage_rm')); break; }
                $this->db->execute("DELETE FROM saved_filters WHERE chat_id=? AND keyword=?", [$this->chatId, strtolower($args[0])]);
                self::send($this->chatId, $this->t('filter.deleted', ['keyword' => $args[0]]));
                break;

            // ===== REPORT =====
            case 'report':
                if (empty($msg['reply_to_message'])) { self::send($this->chatId, $this->t('report.usage')); break; }
                $rep     = $msg['reply_to_message'];
                $repFrom = $rep['from'] ?? [];
                $repFn   = htmlspecialchars($repFrom['first_name'] ?? 'Unknown');
                $repUn   = $repFrom['username'] ?? '';
                $repId   = $repFrom['id'] ?? 0;
                $repMn   = $repUn ? "@$repUn" : "<a href=\"tg://user?id=$repId\">$repFn</a>";
                $reportReason = implode(' ', $args) ?: $this->t('report.no_reason');
                $logText  = $this->t('report.text', [
                    'mention' => $repMn,
                    'reason'  => $reportReason,
                    'by'      => $this->userMention,
                    'msg'     => htmlspecialchars(substr($rep['text'] ?? '[non-text]', 0, 200)),
                    'group'   => htmlspecialchars($msg['chat']['title'] ?? ''),
                ]);
                $logCh = $settings['log_channel'] ?? '';
                if ($logCh) self::api('sendMessage', ['chat_id' => $logCh, 'text' => $logText, 'parse_mode' => 'HTML']);
                $adms = self::api('getChatAdministrators', ['chat_id' => $this->chatId]);
                $notified = 0;
                foreach ($adms['result'] ?? [] as $a) {
                    if (!($a['user']['is_bot'] ?? false)) {
                        self::api('sendMessage', ['chat_id' => $a['user']['id'], 'text' => $logText, 'parse_mode' => 'HTML']);
                        $notified++;
                    }
                }
                self::send($this->chatId, $this->t('report.sent_n', ['n' => $notified]), ['reply_to_message_id' => $msgId]);
                break;

            // ===== KICK BOTS =====
            case 'kickbots':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $adms   = self::api('getChatAdministrators', ['chat_id' => $this->chatId]);
                $botId  = self::api('getMe')['result']['id'] ?? 0;
                $kicked = 0;
                foreach ($adms['result'] ?? [] as $a) {
                    if (($a['user']['is_bot'] ?? false) && $a['user']['id'] !== $botId) {
                        self::kick($this->chatId, $a['user']['id']);
                        $kicked++;
                    }
                }
                self::send($this->chatId, $this->t('cmd.kickbots_done_n', ['n' => $kicked]));
                break;

            // ===== CLEAN DELETED ACCOUNTS =====
            case 'cleandel':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                self::send($this->chatId, $this->t('cmd.cleandel_scanning'));
                $knownIds = $this->db->getKnownChatUsers($this->chatId);
                $kicked   = 0;
                $checked  = 0;
                foreach ($knownIds as $uid) {
                    $uid = (int)$uid;
                    $member = self::getChatMember($this->chatId, $uid);
                    $status = $member['status'] ?? '';
                    if (!in_array($status, ['member', 'restricted', 'administrator', 'creator'], true)) continue;
                    $checked++;
                    $firstName = $member['user']['first_name'] ?? '';
                    $isDeleted = ($member['user']['is_deleted'] ?? false) || $firstName === '';
                    if ($isDeleted) {
                        self::kick($this->chatId, $uid);
                        $kicked++;
                    }
                }
                self::send($this->chatId, $this->t('cmd.cleandel_done', ['checked' => $checked, 'kicked' => $kicked]));
                break;

            // ===== CEK IZIN BOT =====
            case 'checkperms':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $this->checkBotPermissions($this->chatId);
                break;

            // ===== SETTINGS (via command) =====
            case 'settings':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $botUsername = BOT_USERNAME;
                $r = self::send($this->chatId, $this->t('settings.group_prompt'), [
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [
                            ['text' => $this->t('settings.btn_open_here'), 'callback_data' => "settings_menu:{$this->chatId}"],                            
                        ],
                        [
                           ['text' => $this->t('settings.btn_open_pm'),   'url' => "https://t.me/{$botUsername}?start=settings"],
                        ],
                        [['text' => $this->t('kb.close'), 'callback_data' => 'close_msg']],
                    ]])
                ]);
                break;

            // ===== CLEAR ALL MESSAGES =====
            case 'clearall': {
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                // Hitung pesan yang tercatat di message_log (akurat)
                $logCount  = $this->db->countLoggedMessages($this->chatId);
                $showCount = $logCount > 0 ? $logCount : '~1000';
                $confirmText = $this->t('clearall.confirm', ['n' => $showCount]);
                self::send($this->chatId, $confirmText, [
                    'reply_markup' => json_encode(['inline_keyboard' => [[
                        ['text' => $this->t('clearall.btn_yes'), 'callback_data' => "clearall_confirm:{$this->chatId}:0"],
                        ['text' => $this->t('clearall.btn_no'),  'callback_data' => 'clearall_cancel'],
                    ]]])
                ]);
                break;
            }

            case 'setrules':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $rt = !empty($msg['reply_to_message']['text']) ? $msg['reply_to_message']['text'] : implode(' ', $args);
                if (!$rt) { self::send($this->chatId, $this->t('cmd.setrules_usage')); break; }
                $this->db->updateGroupSetting($this->chatId, 'rules', $rt);
                self::send($this->chatId, $this->t('cmd.setrules_done'));
                break;

            case 'setwelcome':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $wt = !empty($msg['reply_to_message']['text']) ? $msg['reply_to_message']['text'] : implode(' ', $args);
                if (!$wt) { self::send($this->chatId, $this->t('cmd.setwelcome_usage')); break; }
                $this->db->updateGroupSetting($this->chatId, 'welcome_message', $wt);
                self::send($this->chatId, $this->t('cmd.setwelcome_done', ['preview' => $this->fmtWelcome($wt, $msg['from'] ?? [], $msg['chat'])]));
                break;

            case 'setgoodbye':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $gt = !empty($msg['reply_to_message']['text']) ? $msg['reply_to_message']['text'] : implode(' ', $args);
                if (!$gt) { self::send($this->chatId, $this->t('cmd.setgoodbye_usage')); break; }
                $this->db->updateGroupSetting($this->chatId, 'goodbye_message', $gt);
                self::send($this->chatId, $this->t('cmd.setgoodbye_done'));
                break;

            case 'setlogchannel':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('cmd.setlogchannel_usage')); break; }
                $this->db->updateGroupSetting($this->chatId, 'log_channel', $args[0]);
                self::send($this->chatId, $this->t('cmd.setlogchannel_done', ['channel' => $args[0]]));
                break;

            case 'ro':
            case 'readonly':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $new = $settings['ro_mode'] ? 0 : 1;
                $this->db->updateGroupSetting($this->chatId, 'ro_mode', $new);
                self::send($this->chatId, $new ? $this->t('ro.on') : $this->t('ro.off'));
                break;

            case 'invitelink':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $r = self::api('exportChatInviteLink', ['chat_id' => $this->chatId]);
                if ($r['result'] ?? null) self::send($this->chatId, $this->t('invite.link', ['link' => $r['result']]));
                break;

            case 'tagall': {
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $extra = implode(' ', $args) ?: $this->t('cmd.tagall_default');

                // Prioritas 1: ambil user yang pernah kirim pesan di grup (48 jam terakhir, maks 500)
                // Ini menggunakan message_log yang dicatat bot, bukan dari API Telegram
                // (Telegram tidak menyediakan endpoint untuk mengambil semua member grup)
                $uids = $this->db->getRecentSenders($this->chatId, 48, 500);

                // Prioritas 2: jika message_log kosong (bot baru join), fallback ke daftar admin
                if (empty($uids)) {
                    $admsResp = self::api('getChatAdministrators', ['chat_id' => $this->chatId]);
                    foreach ($admsResp['result'] ?? [] as $a) {
                        if (!($a['user']['is_bot'] ?? false)) {
                            $uids[] = (int)$a['user']['id'];
                        }
                    }
                }

                if (empty($uids)) {
                    self::send($this->chatId, $this->t('cmd.tagall_no_data'));
                    break;
                }

                // Telegram membatasi maks 100 entities per pesan (per Bot API docs).
                // Tiap invisible mention = 1 entity. Gunakan chunk 80 untuk batas aman.
                // @see https://core.telegram.org/bots/api#formatting-options
                $chunks = array_chunk(array_unique($uids), 80);
                foreach ($chunks as $i => $chunk) {
                    $t = ($i === 0 ? "$extra\n\n" : '');
                    foreach ($chunk as $uid) {
                        // Invisible zero-width space sebagai anchor text — Telegram tetap
                        // mengirim notifikasi mention ke user bersangkutan
                        $t .= "<a href=\"tg://user?id=$uid\">\u{200b}</a>";
                    }
                    self::send($this->chatId, $t);
                    if (count($chunks) > 1) usleep(500000); // 500ms antar pesan jika multi-chunk
                }
                break;
            }

            // ===== GLOBAL BAN =====
            case 'gban':
                if (!$isSa) { self::send($this->chatId, $this->t('error.sa_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $this->db->addGban($targetId, $this->userId, $reason);
                foreach ($this->db->getAllGroups() as $g) {
                    self::broadcastSend('banChatMember', ['chat_id' => (int)$g['chat_id'], 'user_id' => $targetId]);
                }
                self::send($this->chatId, $this->t('gban.done_mention', ['mention' => $targetMention, 'reason' => $reason]));
                break;

            case 'ungban':
                if (!$isSa) { self::send($this->chatId, $this->t('error.sa_only')); break; }
                if (!$targetId) { $this->sendNoTargetError($targetId, $targetMention); break; }
                $this->db->removeGban($targetId);
                self::send($this->chatId, $this->t('gban.undone_mention', ['mention' => $targetMention]));
                break;

            case 'gbanlist':
                if (!$isSa) { self::send($this->chatId, $this->t('error.sa_only')); break; }
                $gbans = $this->db->getGbans(15);
                $cnt   = $this->db->getGbanCount();
                $t = $this->t('cmd.gbanlist_header', ['count' => $cnt]);
                if (empty($gbans)) $t .= $this->t('cmd.gbanlist_empty');
                foreach ($gbans as $g) $t .= "• <code>{$g['user_id']}</code> — " . htmlspecialchars($g['reason']) . "\n";
                self::send($this->chatId, $t);
                break;

            case 'broadcast':
                if (!$isSa) { self::send($this->chatId, $this->t('error.sa_only')); break; }
                $btext   = implode(' ', $args);
                $replyTo = $this->message['reply_to_message'] ?? null;
                $groups  = $this->db->getAllGroups();
                $ok = $fail = 0;

                $bcMediaTypes = ['photo', 'video', 'document', 'audio', 'animation', 'voice', 'video_note'];
                $bcMediaType  = null;
                if ($replyTo) {
                    foreach ($bcMediaTypes as $mt) {
                        if (isset($replyTo[$mt])) { $bcMediaType = $mt; break; }
                    }
                }

                if ($bcMediaType && $replyTo) {
                    // Reply ke pesan media → broadcast media tersebut (rate limited)
                    $fromChatId  = (int)($replyTo['chat']['id'] ?? $this->chatId);
                    $sourceMsgId = (int)($replyTo['message_id'] ?? 0);
                    foreach ($groups as $g) {
                        $res = self::broadcastSend('copyMessage', [
                            'chat_id'      => (int)$g['chat_id'],
                            'from_chat_id' => $fromChatId,
                            'message_id'   => $sourceMsgId,
                        ]);
                        if ($res['ok'] ?? false) $ok++; else $fail++;
                    }
                } elseif ($btext) {
                    // Teks biasa (rate limited)
                    $bcAnnText = $this->t('broadcast.announce', ['text' => $btext]);
                    foreach ($groups as $g) {
                        $res = self::broadcastSend('sendMessage', [
                            'chat_id'                  => (int)$g['chat_id'],
                            'text'                     => $bcAnnText,
                            'parse_mode'               => 'HTML',
                            'disable_web_page_preview' => true,
                        ]);
                        if ($res['ok'] ?? false) $ok++; else $fail++;
                    }
                } else {
                    self::send($this->chatId, $this->t('cmd.broadcast_usage'));
                    break;
                }
                self::send($this->chatId, $this->t('broadcast.done', ['ok' => $ok, 'fail' => $fail]));
                break;

            case 'setwelcomebutton':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                if (empty($args[0])) { self::send($this->chatId, $this->t('cmd.setwelcomebtn_usage')); break; }
                $btnStr = implode(' ', $args);
                $parts  = explode('|', $btnStr, 2);
                if (count($parts) < 2 || !filter_var(trim($parts[1]), FILTER_VALIDATE_URL)) {
                    self::send($this->chatId, $this->t('cmd.setwelcomebtn_invalid')); break;
                }
                $btns = json_decode($settings['welcome_buttons'] ?? '[]', true) ?: [];
                $btns[] = ['text' => trim($parts[0]), 'url' => trim($parts[1])];
                $this->db->updateGroupSetting($this->chatId, 'welcome_buttons', json_encode($btns));
                self::send($this->chatId, $this->t('settings.btn_added_named', ['text' => htmlspecialchars(trim($parts[0]))]));
                break;

            case 'clearwelcomebuttons':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $this->db->updateGroupSetting($this->chatId, 'welcome_buttons', '[]');
                self::send($this->chatId, $this->t('settings.btn_cleared'));
                break;

            case 'setlang':
                if (!$isPriv) { self::send($this->chatId, $this->t('error.admin_only')); break; }
                $curLang = $settings['language'] ?? 'id';
                $txt = $this->t('lang.panel', ['current' => $this->t('lang.name_' . $curLang)]);
                self::send($this->chatId, $txt, ['reply_markup' => json_encode($this->kbLangPanel($this->chatId, false))]);
                break;
        }
    }

    // ================================================================
    //  JOIN / LEFT HANDLER
    // ================================================================

    private function handleJoin(array $msg): void {
        $this->syncGroupInfo($msg['chat']);
        // Cek captcha expired sebelum proses member baru
        $this->checkExpiredCaptchas();
        $settings = $this->db->getGroupSettings($this->chatId);

        $myId = self::api('getMe')['result']['id'] ?? 0;
        foreach ($msg['new_chat_members'] as $nm) {
            $uid = (int)$nm['id'];

            // Bot sendiri bergabung — cek izin dan kirim laporan ke grup
            if ($uid === $myId) {
                $this->syncGroupInfo($msg['chat']);
                $settings = $this->db->getGroupSettings($this->chatId);
                $this->loadLang($settings);
                $this->checkBotPermissions($this->chatId);
                continue;
            }

            // Anti-bot
            if ($settings['anti_bot'] && ($nm['is_bot'] ?? false)) {
                self::ban($this->chatId, $uid);
                continue;
            }

            // Anti-raid
            if ($settings['anti_raid']) {
                if ($this->db->checkRaid($this->chatId, $uid, (int)$settings['raid_limit'])) {
                    self::kick($this->chatId, $uid);
                    self::send($this->chatId, $this->t('join.raid_active'));
                    continue;
                }
            }

            // Global ban
            if ($this->db->isGbanned($uid)) {
                self::ban($this->chatId, $uid);
                $fn = htmlspecialchars($nm['first_name'] ?? '');
                self::send($this->chatId, $this->t('join.gban_kicked', ['mention' => "<a href=\"tg://user?id=$uid\">$fn</a>"]));
                continue;
            }

            // Captcha — mute dulu, lalu kirim soal
            if ($settings['captcha_enabled']) {
                if (!self::mute($this->chatId, $uid)) {
                    // Bot tidak punya izin restrict member — kirim peringatan ke grup
                    self::send($this->chatId, "⚠️ Bot tidak dapat mute anggota baru karena kurang izin <b>Restrict Members</b>. Captcha tidak berfungsi tanpa izin ini.", ['parse_mode' => 'HTML']);
                }
                $this->sendCaptcha($uid, $nm, (int)($settings['captcha_timeout'] ?? 60), trim($settings['rules'] ?? ''), $settings['captcha_type'] ?? 'math');
                continue;
            }

            // Tidak ada captcha, tapi ada peraturan/verifikasi → mute + kirim tombol setuju via DM
            $rulesOnly  = trim($settings['rules'] ?? '');
            $needsAgree = !empty($rulesOnly)
                       || !empty($settings['rules_btn_enabled'])
                       || !empty($settings['require_verification']);
            if ($needsAgree) {
                self::mute($this->chatId, $uid);
                $fn        = htmlspecialchars($nm['first_name'] ?? 'Pengguna');
                $rulesText = !empty($rulesOnly)
                    ? $this->t('captcha.rules_text', ['name' => $fn, 'rules' => $rulesOnly])
                    : $this->t('rules.agree_generic_prompt', ['name' => $fn]);
                $kb = ['inline_keyboard' => [[
                    ['text' => $this->t('captcha.rules_agree_btn'), 'callback_data' => "rules_agree:{$uid}:{$this->chatId}"],
                ]]];
                // Kirim ke DM user
                $dmRes = self::api('sendMessage', [
                    'chat_id'      => $uid,
                    'text'         => $rulesText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode($kb),
                ]);
                if ($dmRes['ok'] ?? false) {
                    // Berhasil DM → notifikasi kecil di grup
                    $mention = "<a href=\"tg://user?id=$uid\">$fn</a>";
                    self::send($this->chatId, $this->t('rules.dm_sent_notif', ['mention' => $mention]));
                } else {
                    // Gagal DM (belum /start bot) → fallback kirim di grup
                    self::send($this->chatId, $rulesText, ['reply_markup' => json_encode($kb)]);
                }
                continue;
            }

            // Welcome message (tidak ada captcha, tidak ada rules)
            if ($settings['welcome_enabled']) {
                $wtext   = $this->fmtWelcome($settings['welcome_message'], $nm, $msg['chat']);
                $buttons = json_decode($settings['welcome_buttons'] ?? '[]', true) ?: [];
                $kb      = null;
                if (!empty($buttons)) {
                    $rows = array_chunk(array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons), 2);
                    $kb   = json_encode(['inline_keyboard' => $rows]);
                }
                self::send($this->chatId, $wtext, array_filter(['reply_markup' => $kb]));
            }

            $this->db->incrementStats($this->chatId, 'total_members');
        }

        // Hapus service message jika clean_service aktif
        if ($settings['clean_service']) {
            self::del($this->chatId, $msg['message_id']);
        }
    }

    private function handleLeft(array $msg): void {
        $settings = $this->db->getGroupSettings($this->chatId);
        if ($settings['goodbye_enabled'] && !($msg['left_chat_member']['is_bot'] ?? false)) {
            $gt = $this->fmtWelcome($settings['goodbye_message'], $msg['left_chat_member'], $msg['chat']);
            self::send($this->chatId, $gt);
        }
        if ($settings['clean_service']) {
            self::del($this->chatId, $msg['message_id']);
        }
    }

    // ================================================================
    //  CALLBACK QUERY HANDLER
    // ================================================================

    private function handleCallback(): void {
        $cb    = $this->callbackQuery;
        $data  = $cb['data'];
        $msgId = $cb['message']['message_id'];
        $cbId  = $cb['id'];
        $parts = explode(':', $data, 4);
        $act   = $parts[0];
        $p1    = $parts[1] ?? '';
        $p2    = $parts[2] ?? '';
        $p3    = $parts[3] ?? '';

        $isSa  = self::isSA($this->userId);
        $isAdm = self::isAdmin($this->chatId, $this->userId);

        // Jika request dari PM dan callback membawa group chat ID di p1,
        // cek apakah user adalah admin di grup tersebut (untuk PM settings).
        if (!$isAdm && $this->chatId > 0 && $p1 && (int)$p1 < 0) {
            $isAdm = self::isAdmin((int)$p1, $this->userId);
        }

        // Tentukan chat target untuk settings (bisa dari p2 atau dari chatId)
        $targetChatId = $p2 ? (int)$p2 : $this->chatId;

        // Muat bahasa grup (atau user lang jika private)
        if ($this->chatId < 0) {
            $cbSettings = $this->db->getGroupSettings($this->chatId);
            $this->loadLang($cbSettings);
        } elseif ($targetChatId < 0) {
            $cbSettings = $this->db->getGroupSettings($targetChatId);
            $this->loadLang($cbSettings);
        }

        switch ($act) {

            // ===== MENU UTAMA =====
            case 'menu_main':
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtStart(), ['reply_markup' => json_encode($this->kbMain())]);
                break;

            case 'menu_help':
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtHelp('overview'), ['reply_markup' => json_encode($this->kbHelp('overview', $this->chatId > 0))]);
                break;

            case 'help_cat': {
                $cat = $this->resolveHelpCategory($p1 ?: 'overview');
                if ($cat === 'sa' && !$isSa) {
                    self::answerCb($cbId, $this->t('help.sa_restricted'), true);
                    break;
                }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtHelp($cat), ['reply_markup' => json_encode($this->kbHelp($cat, $this->chatId > 0))]);
                break;
            }

            case 'menu_features': {
                self::answerCb($cbId);
                $isPm = $this->chatId > 0;
                $navBtn = $isPm
                    ? ['text' => $this->t('kb.back'), 'callback_data' => 'menu_main']
                    : ['text' => $this->t('help.btn_close'), 'callback_data' => 'close_msg'];
                self::edit($this->chatId, $msgId, $this->txtFeatures(), ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('start.btn_help'), 'callback_data' => 'menu_help'], $navBtn],
                ]])]);
                break;
            }

            case 'menu_about':
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtAbout(), ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('kb.back'), 'callback_data' => 'menu_main']]]])]);
                break;

            // ===== PM SETTINGS: DAFTAR GRUP (dari cache DB, cepat) =====
            case 'menu_mygroups': {
                self::answerCb($cbId);
                $groups = $this->db->getGroupsForAdmin($this->userId);
                $kb     = $this->buildGroupListKeyboard($groups);
                if (empty($groups)) {
                    $noGroupKb = ['inline_keyboard' => [[
                        ['text' => $this->t('pm_settings.btn_rescan'), 'callback_data' => 'rescan_groups'],
                        ['text' => $this->t('kb.back'),                'callback_data' => 'menu_main'],
                    ]]];
                    self::edit($this->chatId, $msgId, $this->t('pm_settings.no_groups'), ['reply_markup' => json_encode($noGroupKb)]);
                    break;
                }
                self::edit($this->chatId, $msgId, $this->t('pm_settings.choose_group'), ['reply_markup' => json_encode($kb)]);
                break;
            }

            // ===== RESCAN GRUP — cek status admin via Telegram API =====
            case 'rescan_groups': {
                self::answerCb($cbId, $this->t('pm_settings.scanning'));
                // Edit pesan jadi "scanning..." sementara proses berjalan
                self::edit($this->chatId, $msgId, $this->t('pm_settings.scanning'));
                $groups = $this->scanAdminGroups();
                $kb     = $this->buildGroupListKeyboard($groups);
                if (empty($groups)) {
                    $noGroupKb = ['inline_keyboard' => [[
                        ['text' => $this->t('pm_settings.btn_rescan'), 'callback_data' => 'rescan_groups'],
                        ['text' => $this->t('kb.back'),                'callback_data' => 'menu_main'],
                    ]]];
                    self::edit($this->chatId, $msgId, $this->t('pm_settings.no_groups'), ['reply_markup' => json_encode($noGroupKb)]);
                    break;
                }
                self::edit($this->chatId, $msgId, $this->t('pm_settings.choose_group'), ['reply_markup' => json_encode($kb)]);
                break;
            }

            // ===== PM SETTINGS: BUKA PANEL SETTINGS GRUP =====
            case 'pm_settings': {
                $cid = (int)$p1;
                if (!$isSa && !self::isAdmin($cid, $this->userId)) {
                    self::answerCb($cbId, $this->t('pm_settings.not_admin'), true);
                    break;
                }
                self::answerCb($cbId);
                $s = $this->db->getGroupSettings($cid);
                $this->loadLang($s);
                self::edit($this->chatId, $msgId, $this->txtSettings($s, $cid), ['reply_markup' => json_encode($this->kbSettings($s, $cid, true))]);
                break;
            }

            // ===== LANGUAGE PANEL =====
            case 'lang_panel': {
                self::answerCb($cbId);
                // Tampilkan panel pemilihan bahasa (private: per-user; di group: per-group)
                $cid    = $this->chatId < 0 ? 0 : 0;
                $curLangKey = $this->chatId < 0
                    ? ($this->db->getGroupSettings($this->chatId)['language'] ?? 'id')
                    : ($this->db->getBotSetting('user_lang_' . $this->userId, 'id'));
                $curName = $this->t('lang.name_' . $curLangKey);
                $txt = $this->t('lang.panel', ['current' => $curName]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbLangPanel(0))]);
                break;
            }

            case 'setlang_user': {
                // p1 = lang code (id/en)
                $newLang = in_array($p1, ['id','en']) ? $p1 : 'id';
                $this->db->setBotSetting('user_lang_' . $this->userId, $newLang);
                Lang::load($newLang);
                $this->currentLang = $newLang;
                $langName = $this->t('lang.name_' . $newLang);
                self::answerCb($cbId, $this->t('lang.changed', ['lang' => $langName]), false);
                self::edit($this->chatId, $msgId, $this->txtStart(), ['reply_markup' => json_encode($this->kbMain())]);
                break;
            }

            case 'setlang_group': {
                // p1 = lang code, p2 = group chat id
                if (!$isSa && !self::isAdmin($targetChatId, $this->userId)) {
                    self::answerCb($cbId, $this->t('error.admin_only'), true); break;
                }
                $newLang = in_array($p1, ['id','en']) ? $p1 : 'id';
                $this->db->updateGroupSetting($targetChatId, 'language', $newLang);
                Lang::load($newLang);
                $this->currentLang = $newLang;
                $langName = $this->t('lang.name_' . $newLang);
                self::answerCb($cbId, $this->t('lang.changed', ['lang' => $langName]), false);
                $newS = $this->db->getGroupSettings($targetChatId);
                self::edit($this->chatId, $msgId, $this->txtSettings($newS, $targetChatId), ['reply_markup' => json_encode($this->kbSettings($newS, $targetChatId))]);
                break;
            }

            case 'settings_setlang': {
                // Tampilkan pilihan bahasa grup dari settings menu
                if (!$isSa && !self::isAdmin($targetChatId, $this->userId)) {
                    self::answerCb($cbId, $this->t('error.admin_only'), true); break;
                }
                self::answerCb($cbId);
                $curS    = $this->db->getGroupSettings($targetChatId);
                $curLang = $curS['language'] ?? 'id';
                $curName = $this->t('lang.name_' . $curLang);
                $txt = $this->t('lang.panel', ['current' => $curName]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbLangPanel($targetChatId))]);
                break;
            }

            // ===== SUPERADMIN PANEL =====
            case 'sa_panel':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                break;

            // ── Kategori Broadcast ──
            case 'sa_cat_broadcast':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_broadcast_title'), ['reply_markup' => json_encode($this->kbSACatBroadcast())]);
                break;

            // ── Kategori Statistik & Grup ──
            case 'sa_cat_stats':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_stats_title'), ['reply_markup' => json_encode($this->kbSACatStats())]);
                break;

            // ── Kategori Manajemen User ──
            case 'sa_cat_users':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_users_title').'     ', ['reply_markup' => json_encode($this->kbSACatUsers())]);
                break;

            // ── Kategori Pengaturan Bot ──
            case 'sa_cat_settings':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_settings_title'), ['reply_markup' => json_encode($this->kbSACatSettings())]);
                break;

            // ── Kategori Layanan Eksternal ──
            case 'sa_cat_services':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_services_title'), ['reply_markup' => json_encode($this->kbSACatServices())]);
                break;

            // ── Kategori Sistem ──
            case 'sa_cat_system':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->t('sa.cat_system_title'), ['reply_markup' => json_encode($this->kbSACatSystem())]);
                break;

            // ── Verifikasi Member (daftar grup) ──
            case 'sa_verif': {
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $groupCounts = $this->db->query(
                    "SELECT chat_id, COUNT(*) as cnt FROM member_verifications GROUP BY chat_id ORDER BY cnt DESC"
                );
                $txt  = $this->t('sa.verif_title') . "\n\n";
                $rows = [];
                foreach ($groupCounts as $gc) {
                    $gs    = $this->db->getGroupSettings((int)$gc['chat_id']);
                    $title = htmlspecialchars($gs['title'] ?: 'Grup tanpa nama');
                    $rows[] = [['text' => "👥 {$title} ({$gc['cnt']})", 'callback_data' => "sa_verif_group:{$gc['chat_id']}:0"]];
                }
                if (empty($rows)) {
                    $txt .= $this->t('sa.verif_empty');
                }
                $rows[] = [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']];
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => $rows])]);
                break;
            }

            // ── Verifikasi Member (detail grup, paginasi) ──
            case 'sa_verif_group': {
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $gChatId = (int)$p1;
                $page    = max(0, (int)($p2 !== '' ? $p2 : 0));
                $perPage = 8;
                $all     = $this->db->getGroupVerifications($gChatId, 500);
                $total   = count($all);
                $slice   = array_slice($all, $page * $perPage, $perPage);

                $gs       = $this->db->getGroupSettings($gChatId);
                $grpTitle = htmlspecialchars($gs['title'] ?: 'Grup ' . $gChatId);

                // Bangun teks langsung (tidak via template agar aman dari HTML/float issues)
                $txt = "🔐 <b>Verifikasi — {$grpTitle}</b>\n";
                $txt .= "📋 Total terverifikasi: <b>{$total}</b>\n";
                $txt .= "📄 Halaman " . ($page + 1) . " / " . max(1, (int)ceil($total / $perPage)) . "\n\n";

                if (empty($slice)) {
                    $txt .= "<i>Tidak ada data verifikasi.</i>";
                } else {
                    foreach ($slice as $idx => $v) {
                        $no    = ($page * $perPage) + $idx + 1;
                        $name  = htmlspecialchars(trim($v['first_name'] ?? '')) ?: '-';
                        $un    = !empty($v['username']) ? '@' . htmlspecialchars($v['username']) : '-';
                        $uid   = (int)$v['user_id'];
                        $phone = htmlspecialchars($v['phone_number'] ?? '-');
                        $lat   = round((float)($v['latitude']  ?? 0), 6);
                        $lon   = round((float)($v['longitude'] ?? 0), 6);
                        $time  = date('d/m/y H:i', (int)($v['verified_at'] ?? 0));

                        $txt .= "<b>{$no}.</b> <b>{$name}</b> ({$un})\n";
                        $txt .= "   🆔 <code>{$uid}</code>\n";
                        $txt .= "   📱 <code>{$phone}</code>\n";
                        $txt .= "   📍 <a href='https://www.google.com/maps?q={$lat},{$lon}'>Lihat di Google Maps</a>\n";
                        $txt .= "   🕒 {$time}\n\n";
                    }
                }

                $navRow = [];
                if ($page > 0) {
                    $navRow[] = ['text' => '◀️ Prev', 'callback_data' => "sa_verif_group:{$gChatId}:" . ($page - 1)];
                }
                if (($page + 1) * $perPage < $total) {
                    $navRow[] = ['text' => 'Next ▶️', 'callback_data' => "sa_verif_group:{$gChatId}:" . ($page + 1)];
                }
                $kbRows = [];
                if (!empty($navRow)) $kbRows[] = $navRow;
                $kbRows[] = [['text' => '⬅️ Kembali ke Daftar', 'callback_data' => 'sa_verif']];

                $res = self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => $kbRows])]);
                if (!($res['ok'] ?? false)) {
                    // Jika edit gagal (misal pesan terlalu panjang), kirim pesan baru
                    $errDesc = $res['description'] ?? 'unknown';
                    if (str_contains($errDesc, 'message is too long')) {
                        // Potong dan coba lagi
                        $txt = mb_substr($txt, 0, 3500) . "\n\n<i>...dipotong karena terlalu panjang</i>";
                        self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => $kbRows])]);
                    }
                }
                break;
            }

            case 'sa_groups':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $allGroups = $this->db->getAllGroups();
                $page    = max(0, (int)$p1);
                $perPage = 10;
                $total   = count($allGroups);
                $slice   = array_slice($allGroups, $page * $perPage, $perPage);
                $totalPage = max(1, (int)ceil($total / $perPage));
                $t = $this->t('sa.groups_header', ['total' => $total, 'page' => $page + 1, 'total_page' => $totalPage]);
                foreach ($slice as $g) {
                    $title = htmlspecialchars($g['title'] ?: $this->t('sa.groups_no_name'));
                    $un    = $g['username'] ? "@{$g['username']}" : "<code>{$g['chat_id']}</code>";
                    $t    .= "• <b>$title</b> — $un\n";
                }
                if (empty($slice)) $t .= $this->t('sa.groups_empty');
                $navRow = [];
                if ($page > 0) $navRow[] = ['text' => '◀️ Prev', 'callback_data' => "sa_groups:" . ($page - 1)];
                if (($page + 1) * $perPage < $total) $navRow[] = ['text' => 'Next ▶️', 'callback_data' => "sa_groups:" . ($page + 1)];
                $btnRows = [];
                if ($navRow) $btnRows[] = $navRow;
                $btnRows[] = [['text' => $this->t('sa.groups_back'), 'callback_data' => 'sa_panel']];
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $btnRows])]);
                break;

            case 'sa_stats':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $stats = $this->db->getGlobalStats();
                $maint = $this->db->getBotSetting('maintenance_mode', '0');
                $totalSA = count(SUPERADMINS) + $stats['total_dyn_sa'];
                $t = $this->t('sa.stats_text', [
                    'groups'    => $stats['total_groups'],
                    'users'     => $stats['total_users'],
                    'gban'      => $stats['total_gban'],
                    'broadcast' => $stats['total_broadcast'],
                    'warns'     => $stats['total_warnings'],
                    'bans'      => $stats['total_bans'],
                    'mutes'     => $stats['total_mutes'],
                    'messages'  => number_format($stats['total_messages']),
                    'notes'     => $stats['total_notes'],
                    'filters'   => $stats['total_filters'],
                    'total_sa'  => $totalSA,
                    'maint'     => ($maint === '1' ? $this->t('sa.stats_maint_on') : $this->t('sa.stats_maint_off')),
                    'time'      => date('d/m/Y H:i:s'),
                ]);
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('sa.stats_refresh_btn'), 'callback_data' => 'sa_stats']],
                    [['text' => $this->t('sa.stats_back_btn'), 'callback_data' => 'sa_panel']],
                ]])]);
                break;

            case 'sa_gbanlist':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $gbans = $this->db->getGbans(20);
                $cnt   = $this->db->getGbanCount();
                $t = $this->t('sa.gbanlist_header', ['count' => $cnt]);
                if (empty($gbans)) {
                    $t .= $this->t('sa.gbanlist_empty_text');
                } else {
                    foreach ($gbans as $g) {
                        $reason = htmlspecialchars($g['reason']);
                        $t .= "• <code>{$g['user_id']}</code> — $reason\n";
                    }
                    if ($cnt > 20) $t .= $this->t('sa.gbanlist_more', ['n' => $cnt - 20]);
                }
                $gbKb = [
                    [['text' => $this->t('sa.gbanlist_add_btn'), 'callback_data' => 'sa_gban_add_prompt'],
                     ['text' => $this->t('sa.gbanlist_rm_btn'),  'callback_data' => 'sa_gban_rm_prompt']],
                ];
                if (in_array($this->userId, SUPERADMINS) && $cnt > 0) {
                    $gbKb[] = [['text' => $this->t('sa.gbanlist_clear_btn'), 'callback_data' => 'sa_cleargban_confirm']];
                }
                $gbKb[] = [['text' => $this->t('sa.gbanlist_back_btn'), 'callback_data' => 'sa_panel']];
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $gbKb])]);
                break;

            case 'sa_cleargban_confirm':
                if (!$isSa || !in_array($this->userId, SUPERADMINS)) { self::answerCb($cbId, $this->t('error.owner_only'), true); break; }
                self::answerCb($cbId);
                $cnt = $this->db->getGbanCount();
                self::edit($this->chatId, $msgId,
                    $this->t('sa.cleargban_confirm_txt', ['count' => $cnt]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.cleargban_yes_btn'), 'callback_data' => 'sa_cleargban_do'],
                         ['text' => $this->t('sa.cleargban_cancel_btn'), 'callback_data' => 'sa_gbanlist']],
                    ]])]);
                break;

            case 'sa_cleargban_do':
                if (!$isSa || !in_array($this->userId, SUPERADMINS)) { self::answerCb($cbId, $this->t('error.owner_only'), true); break; }
                $cnt = $this->db->getGbanCount();
                $this->db->execute("DELETE FROM global_bans");
                self::answerCb($cbId, $this->t('sa.cleargban_done_alert'));
                self::edit($this->chatId, $msgId,
                    $this->t('sa.cleargban_done_txt', ['count' => $cnt]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.cleargban_back_btn'), 'callback_data' => 'sa_gbanlist']],
                    ]])]);
                break;

            case 'sa_gban_add_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.gban_add_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, 0, 'sa_gban_add', $promptId, $msgId);
                break;

            case 'sa_gban_rm_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.gban_rm_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, 0, 'sa_gban_remove', $promptId, $msgId);
                break;

            case 'sa_broadcast_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $gc = count($this->db->getAllGroups());
                $r  = self::send($this->chatId, $this->t('sa.broadcast_prompt', ['count' => $gc]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, 0, 'sa_broadcast', $promptId, $msgId);
                break;

            case 'sa_cancel_input':
                self::answerCb($cbId, $this->t('sa.cancelled_input'));
                $this->db->clearPendingInput($this->userId);
                self::del($this->chatId, $msgId);
                break;

            case 'sa_broadcast_history':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $hist = $this->db->query("SELECT * FROM broadcast_history ORDER BY sent_at DESC LIMIT 5");
                $t = $this->t('sa.broadcast_history_text');
                if (empty($hist)) { $t .= $this->t('sa.broadcast_history_empty'); }
                $mediaIcons = [
                    '[photo]'      => '🖼️', '[video]'      => '🎥', '[document]'  => '📄',
                    '[audio]'      => '🎵', '[animation]'  => '🎞️', '[voice]'     => '🎙️',
                    '[video_note]' => '📹',
                ];
                foreach ($hist as $h) {
                    $snippet = $h['message'];
                    $icon = '💬';
                    foreach ($mediaIcons as $tag => $ico) {
                        if (strpos($snippet, $tag) === 0) { $icon = $ico; $snippet = ltrim(substr($snippet, strlen($tag))); break; }
                    }
                    $t .= "{$icon} " . date('d/m H:i', $h['sent_at']) . " — ✅{$h['success_count']} ❌{$h['fail_count']}\n";
                    $t .= "<i>" . htmlspecialchars(mb_substr(trim($snippet), 0, 80)) . (mb_strlen(trim($snippet)) > 80 ? '...' : '') . "</i>\n\n";
                }
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.stats_back_btn'), 'callback_data' => 'sa_panel']]]])]);
                break;

            // ===== JADWAL BROADCAST: PROMPT =====
            case 'sa_schedule_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $gc = count($this->db->getAllGroups());
                $r  = self::send($this->chatId, $this->t('sa.schedule_prompt_msg', ['count' => $gc]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, 0, 'sa_schedule_msg', $promptId, $msgId);
                break;

            // ===== JADWAL BROADCAST: DAFTAR & BATAL =====
            case 'sa_schedule_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $queue = $this->db->getScheduleQueue($this->userId, 10);
                $t     = $this->t('sa.schedule_list_title');
                $rows  = [];
                if (empty($queue)) {
                    $t .= $this->t('sa.schedule_list_empty');
                } else {
                    $mediaIcons2 = [
                        'photo' => '🖼️', 'video' => '🎥', 'document' => '📄',
                        'audio' => '🎵', 'animation' => '🎞️', 'voice' => '🎙️', 'video_note' => '📹',
                    ];
                    foreach ($queue as $q) {
                        $ico    = isset($q['media_type'], $mediaIcons2[$q['media_type']]) ? $mediaIcons2[$q['media_type']] : '💬';
                        $when   = date('d/m H:i', (int)$q['scheduled_at']);
                        $prev   = $q['media_type'] ? ($q['caption'] ?: '(media)') : $q['message'];
                        $prev   = mb_substr(htmlspecialchars($prev), 0, 30);
                        $t     .= "{$ico} <b>{$when}</b> — <i>{$prev}</i>\n";
                        $rows[] = [['text' => "🗑 Batal #{$q['id']} ({$when})", 'callback_data' => 'sa_schedule_cancel:' . $q['id']]];
                    }
                }
                $rows[] = [['text' => $this->t('sa.stats_back_btn'), 'callback_data' => 'sa_panel']];
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $rows])]);
                break;

            case 'sa_schedule_cancel':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $cancelId = (int)$p1;
                if ($cancelId && $this->db->cancelSchedule($cancelId, $this->userId)) {
                    self::answerCb($cbId, $this->t('sa.schedule_cancel_done'));
                    // Refresh daftar
                    $queue2 = $this->db->getScheduleQueue($this->userId, 10);
                    $t2     = $this->t('sa.schedule_list_title');
                    $rows2  = [];
                    $mediaI = ['photo'=>'🖼️','video'=>'🎥','document'=>'📄','audio'=>'🎵','animation'=>'🎞️','voice'=>'🎙️','video_note'=>'📹'];
                    if (empty($queue2)) { $t2 .= $this->t('sa.schedule_list_empty'); }
                    foreach ($queue2 as $q2) {
                        $ico2  = $mediaI[$q2['media_type'] ?? ''] ?? '💬';
                        $wh2   = date('d/m H:i', (int)$q2['scheduled_at']);
                        $pr2   = mb_substr(htmlspecialchars($q2['media_type'] ? ($q2['caption'] ?: '(media)') : $q2['message']), 0, 30);
                        $t2   .= "{$ico2} <b>{$wh2}</b> — <i>{$pr2}</i>\n";
                        $rows2[] = [['text' => "🗑 Batal #{$q2['id']} ({$wh2})", 'callback_data' => 'sa_schedule_cancel:' . $q2['id']]];
                    }
                    $rows2[] = [['text' => $this->t('sa.stats_back_btn'), 'callback_data' => 'sa_panel']];
                    self::edit($this->chatId, $msgId, $t2, ['reply_markup' => json_encode(['inline_keyboard' => $rows2])]);
                } else {
                    self::answerCb($cbId, $this->t('sa.schedule_cancel_fail'), true);
                }
                break;

            // ===== KELOLA SUPERADMIN =====
            case 'sa_salist':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $staticSAs  = SUPERADMINS;
                $dynamicSAs = $this->db->getDynamicSuperadmins();
                $t = $this->t('sa.salist_text');
                foreach ($staticSAs as $sid) $t .= "• <code>$sid</code>\n";
                if (!empty($dynamicSAs)) {
                    $t .= $this->t('sa.salist_dyn_header');
                    foreach ($dynamicSAs as $d) {
                        $dt = date('d/m/Y', $d['added_at']);
                        $t .= "• <code>{$d['user_id']}</code>" . ($d['note'] ? " — {$d['note']}" : '') . " <i>($dt)</i>\n";
                    }
                } else {
                    $t .= $this->t('sa.salist_no_dyn');
                }
                $rows = [];
                if (in_array($this->userId, SUPERADMINS)) {
                    $rows[] = [
                        ['text' => $this->t('sa.addsa_btn'), 'callback_data' => 'sa_addsa_prompt'],
                        ['text' => $this->t('sa.rmsa_btn'),  'callback_data' => 'sa_rmsa_prompt'],
                    ];
                }
                $rows[] = [['text' => $this->t('sa.salist_back_btn'), 'callback_data' => 'sa_panel']];
                self::edit($this->chatId, $msgId, $t, ['reply_markup' => json_encode(['inline_keyboard' => $rows])]);
                break;

            case 'sa_addsa_prompt':
                if (!$isSa || !in_array($this->userId, SUPERADMINS)) { self::answerCb($cbId, $this->t('error.owner_only'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.addsa_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_addsa', $r['result']['message_id'] ?? 0, $msgId);
                break;

            case 'sa_rmsa_prompt':
                if (!$isSa || !in_array($this->userId, SUPERADMINS)) { self::answerCb($cbId, $this->t('error.owner_only'), true); break; }
                $dynamicSAs = $this->db->getDynamicSuperadmins();
                if (empty($dynamicSAs)) {
                    self::answerCb($cbId, $this->t('sa.no_dyn_sa_to_rm'), true);
                    break;
                }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.rmsa_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_rmsa', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== LOG BOT =====
            case 'sa_logs':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $logPath = LOG_PATH;
                if (!file_exists($logPath) || filesize($logPath) === 0) {
                    self::edit($this->chatId, $msgId, $this->t('sa.logs_empty_text'), ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.logs_back_btn'), 'callback_data' => 'sa_panel']],
                    ]])]);
                    break;
                }
                $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $last  = array_slice($lines, -25);
                $logText = $this->t('sa.logs_text', ['n' => 25, 'log' => htmlspecialchars(implode("\n", $last))]);
                if (strlen($logText) > 4000) {
                    $last    = array_slice($lines, -10);
                    $logText = $this->t('sa.logs_text', ['n' => 10, 'log' => htmlspecialchars(implode("\n", $last))]);
                }
                self::edit($this->chatId, $msgId, $logText, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('sa.logs_clear_btn'), 'callback_data' => 'sa_clear_logs'],
                     ['text' => $this->t('sa.logs_refresh_btn'), 'callback_data' => 'sa_logs']],
                    [['text' => $this->t('sa.logs_back_btn'), 'callback_data' => 'sa_panel']],
                ]])]);
                break;

            case 'sa_clear_logs':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                file_put_contents(LOG_PATH, '');
                self::answerCb($cbId, $this->t('sa.logs_clear_alert'));
                self::edit($this->chatId, $msgId, $this->t('sa.logs_cleared_txt'), ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('sa.logs_back_btn'), 'callback_data' => 'sa_panel']],
                ]])]);
                break;

            // ===== BACKUP DATABASE =====
            case 'sa_backup':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('sa.backup_sending_alert'));
                $dbPath = DB_PATH;
                if (!file_exists($dbPath)) {
                    self::send($this->chatId, $this->t('sa.backup_not_found_txt'));
                    break;
                }
                $size = number_format(filesize($dbPath) / 1024, 1);
                $curl = curl_init(API_URL . 'sendDocument');
                curl_setopt_array($curl, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => [
                        'chat_id'    => $this->chatId,
                        'document'   => new CURLFile($dbPath, 'application/octet-stream', 'bot_backup_' . date('Ymd_His') . '.db'),
                        'caption'    => $this->t('sa.backup_caption', ['date' => date('d/m/Y H:i:s'), 'size' => $size]),
                        'parse_mode' => 'HTML',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                ]);
                curl_exec($curl);
                curl_close($curl);
                break;

            // ===== PING =====
            case 'sa_ping':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $t0 = microtime(true);
                self::api('getMe');
                $ms = round((microtime(true) - $t0) * 1000);
                self::answerCb($cbId, $this->t('sa.ping_text', ['ms' => $ms]), true);
                break;

            // ===== INFO SERVER / VPS / HOSTING =====
            case 'sa_sysinfo':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('sa.sysinfo_alert'));
                $sysKb = json_encode(['inline_keyboard' => [
                    [['text' => $this->t('sa.sysinfo_refresh_btn'), 'callback_data' => 'sa_sysinfo']],
                    [['text' => $this->t('sa.sysinfo_back_btn'),    'callback_data' => 'sa_panel']],
                ]]);
                self::edit($this->chatId, $msgId, $this->txtSysInfo(), ['reply_markup' => $sysKb]);
                break;

            // ===== MAINTENANCE MODE =====
            case 'sa_maintenance':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $cur = $this->db->getBotSetting('maintenance_mode', '0');
                $new = $cur === '1' ? '0' : '1';
                $this->db->setBotSetting('maintenance_mode', $new);
                $statusTxt = $new === '1' ? $this->t('sa.maint_status_on') : $this->t('sa.maint_status_off');
                self::answerCb($cbId, $this->t('sa.maint_alert', ['status' => $statusTxt]));
                self::edit($this->chatId, $msgId, $this->txtSA(), ['reply_markup' => json_encode($this->kbSA())]);
                break;

            // ===== PENGATURAN AI DEFAULT (CHAT & IMAGE) =====
            case 'sa_ai_settings':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtSaAiSettings(), ['reply_markup' => json_encode($this->kbSaAiSettings())]);
                break;

            case 'sa_ai_chat_menu':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtSaAiChatMenu(), ['reply_markup' => json_encode($this->kbSaAiChatMenu())]);
                break;

            case 'sa_ai_image_menu':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtSaAiImageMenu(), ['reply_markup' => json_encode($this->kbSaAiImageMenu())]);
                break;

            case 'sa_set_ai_chat': {
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $modelId = trim((string)$p1);
                if (!isset(AiConfig::CHAT_MODELS[$modelId])) {
                    self::answerCb($cbId, '❌ Model tidak valid.', true);
                    break;
                }
                $this->db->setDefaultAiChat($modelId);
                $info = AiConfig::getChatModelInfo($modelId);
                self::answerCb($cbId, $this->t('sa.ai_chat_updated', ['label' => $info['short']]));
                self::edit($this->chatId, $msgId, $this->txtSaAiChatMenu(), ['reply_markup' => json_encode($this->kbSaAiChatMenu())]);
                break;
            }

            case 'sa_set_ai_image': {
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $modelId = trim((string)$p1);
                if (!isset(AiConfig::IMAGE_MODELS[$modelId])) {
                    self::answerCb($cbId, '❌ Model tidak valid.', true);
                    break;
                }
                $this->db->setDefaultAiImage($modelId);
                $info = AiConfig::getImageModelInfo($modelId);
                self::answerCb($cbId, $this->t('sa.ai_image_updated', ['label' => $info['short']]));
                self::edit($this->chatId, $msgId, $this->txtSaAiImageMenu(), ['reply_markup' => json_encode($this->kbSaAiImageMenu())]);
                break;
            }

            // ===== SETTING GAMBAR AI =====
            case 'sa_img_settings':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $curLimit = (int)$this->db->getBotSetting('img_daily_limit', '5');
                self::edit($this->chatId, $msgId,
                    $this->t('sa.img_settings_title', ['limit' => $curLimit === 0 ? '∞' : $curLimit]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '✏️ Ubah Limit', 'callback_data' => 'sa_img_limit_prompt']],
                        [['text' => '🤖 Pengaturan AI', 'callback_data' => 'sa_ai_settings']],
                        [['text' => '🔙 Superadmin', 'callback_data' => 'sa_panel']],
                    ]])]);
                break;

            case 'sa_img_limit_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.img_limit_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_img_limit', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== LOOKUP USER (via prompt) =====
            case 'sa_lookup_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId, $this->t('sa.lookup_prompt'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('sa.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_lookup', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== QUICK GBAN dari lookup =====
            case 'sa_quick_gban':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $uid = (int)$p1;
                if (!$uid) { self::answerCb($cbId, $this->t('sa.quick_gban_id_invalid'), true); break; }
                $this->db->addGban($uid, $this->userId, $this->t('sa.quick_gban_reason'));
                self::answerCb($cbId, $this->t('sa.quick_gban_done_alert', ['uid' => $uid]), true);
                $uc = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [$uid]);
                $fn = htmlspecialchars(trim(($uc['first_name'] ?? $uid) . ' ' . ($uc['last_name'] ?? '')));
                self::edit($this->chatId, $msgId,
                    $this->t('sa.quick_gban_done_txt', ['name' => $fn, 'uid' => $uid]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.quick_ungban_btn'), 'callback_data' => "sa_quick_ungban:$uid"]],
                    ]])]);
                break;

            case 'sa_quick_ungban':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $uid = (int)$p1;
                $this->db->removeGban($uid);
                self::answerCb($cbId, $this->t('sa.quick_ungban_done_alert', ['uid' => $uid]), true);
                $uc = $this->db->fetchOne("SELECT * FROM user_cache WHERE user_id = ?", [$uid]);
                $fn = htmlspecialchars(trim(($uc['first_name'] ?? $uid) . ' ' . ($uc['last_name'] ?? '')));
                self::edit($this->chatId, $msgId,
                    $this->t('sa.quick_ungban_done_txt', ['name' => $fn, 'uid' => $uid]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('sa.quick_gban_btn'), 'callback_data' => "sa_quick_gban:$uid"]],
                    ]])]);
                break;

            // ================================================================
            //  SA EMAIL PANEL
            // ================================================================

            case 'sa_email':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtEmailPanel(), ['reply_markup' => json_encode($this->kbEmailPanel())]);
                break;

            // ===== DAFTAR AKUN TERSIMPAN =====
            case 'sa_email_accounts':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $emailAccts = $this->db->getEmailAccounts();
                $txt = $this->t('email.accounts_header');
                if (empty($emailAccts)) {
                    $txt .= $this->t('email.accounts_empty');
                } else {
                    foreach ($emailAccts as $i => $ea) {
                        $label = $ea['label'] ? " <i>({$ea['label']})</i>" : '';
                        $txt  .= ($i + 1) . ". <code>{$ea['email']}</code>{$label}\n";
                        $txt  .= $this->t('email.accounts_row', ['quota' => $ea['quota'], 'date' => date('d/m/Y', $ea['added_at'])]);
                    }
                }
                $rows = [];
                foreach ($emailAccts as $ea) {
                    $rows[] = [['text' => '📭 ' . $ea['email'], 'callback_data' => 'sa_email_acct:' . $ea['id']]];
                }
                $rows[] = [
                    ['text' => $this->t('email.kb_create'), 'callback_data' => 'sa_email_create_prompt'],
                    ['text' => $this->t('email.kb_sync'),   'callback_data' => 'sa_email_sync_cpanel'],
                ];
                $rows[] = [['text' => $this->t('email.kb_back'), 'callback_data' => 'sa_email']];
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => $rows])]);
                break;

            // ===== DETAIL AKUN TERSIMPAN =====
            case 'sa_email_acct':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $acctId = (int)$p1;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $txt = $this->t('email.acct_detail', [
                    'email' => $ea['email'],
                    'pass'  => $ea['email_password'],
                    'quota' => $ea['quota'],
                    'label' => $ea['label'] ? $this->t('email.acct_detail_label', ['label' => $ea['label']]) : '',
                    'date'  => date('d/m/Y H:i', $ea['added_at']),
                ]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_inbox'),    'callback_data' => "sa_email_inbox:{$acctId}:0"],
                     ['text' => $this->t('email.kb_compose'),  'callback_data' => "sa_email_compose:{$acctId}"]],
                    [['text' => $this->t('email.kb_test_conn'),'callback_data' => "sa_email_test:{$acctId}"]],
                    [['text' => $this->t('email.kb_del_acct'), 'callback_data' => "sa_email_del_acct:{$acctId}"]],
                    [['text' => $this->t('email.kb_back'),     'callback_data' => 'sa_email_accounts']],
                ]])]);
                break;

            // ===== TES KONEKSI IMAP+SMTP =====
            case 'sa_email_test':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('email.test_alert'));
                $acctId = (int)$p1;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $cfg = EmailManager::getRuntimeConfig();
                // Test IMAP
                $imapErr = '';
                $imapSock = EmailManager::testImapConnect($ea['email'], $ea['email_password'], $imapErr);
                $imapOk   = ($imapSock !== false);
                if ($imapSock) { @fclose($imapSock); }
                // Test SMTP port 465
                $smtpErr465 = '';
                $smtpOk465  = EmailManager::testSmtpConnect($ea['email'], $ea['email_password'], $cfg['mail_host'], 465, true, $smtpErr465);
                // Test SMTP port 587
                $smtpErr587 = '';
                $smtpOk587  = EmailManager::testSmtpConnect($ea['email'], $ea['email_password'], $cfg['mail_host'], 587, false, $smtpErr587);
                $imapLine  = $imapOk  ? "✅ IMAP 993 (SSL) — OK" : "❌ IMAP 993 — " . htmlspecialchars($imapErr);
                $smtpLine1 = $smtpOk465 ? "✅ SMTP 465 (SSL) — OK" : "❌ SMTP 465 — " . htmlspecialchars($smtpErr465);
                $smtpLine2 = $smtpOk587 ? "✅ SMTP 587 (TLS) — OK" : "❌ SMTP 587 — " . htmlspecialchars($smtpErr587);
                $testTxt = $this->t('email.test_result', [
                    'email'   => htmlspecialchars($ea['email']),
                    'host'    => htmlspecialchars($cfg['mail_host']),
                    'imap'    => $imapLine,
                    'smtp465' => $smtpLine1,
                    'smtp587' => $smtpLine2,
                ]);
                self::send($this->chatId, $testTxt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_back'), 'callback_data' => "sa_email_acct:{$acctId}"]],
                ]])]);
                break;

            // ===== HAPUS AKUN TERSIMPAN (KONFIRMASI) =====
            case 'sa_email_del_acct':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $acctId = (int)$p1;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                self::edit($this->chatId, $msgId,
                    $this->t('email.del_confirm', ['email' => $ea['email']]),
                    ['reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => $this->t('email.kb_del_confirm'), 'callback_data' => "sa_email_del_acct_do:{$acctId}"],
                         ['text' => $this->t('sa.cleargban_cancel_btn'), 'callback_data' => "sa_email_acct:{$acctId}"]],
                    ]])]);
                break;

            case 'sa_email_del_acct_do':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('email.deleting_alert'));
                $acctId = (int)$p1;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) {
                    self::edit($this->chatId, $msgId, $this->t('email.not_found_txt'));
                    break;
                }
                $emailAddr = $ea['email'] ?? '';
                // Parse user@domain
                $atPos     = strrpos($emailAddr, '@');
                $emailUser = $atPos !== false ? substr($emailAddr, 0, $atPos) : $emailAddr;
                $emailDom  = $atPos !== false ? substr($emailAddr, $atPos + 1) : '';
                // Hapus dari cPanel
                $cpResult  = ($emailUser && $emailDom)
                    ? EmailManager::deleteAccount($emailUser, $emailDom)
                    : ['status' => 0, 'errors' => [$this->t('email.invalid_fmt_cpanel')]];
                $cpOk      = !empty($cpResult['status']) && $cpResult['status'] == 1;
                $cpErr     = htmlspecialchars(implode(', ', $cpResult['errors'] ?? []));
                // Hapus dari database bot
                $this->db->deleteEmailAccount($acctId);
                $cpStatus = $cpOk
                    ? $this->t('email.del_cpanel_ok')
                    : $this->t('email.del_cpanel_fail', ['err' => $cpErr]);
                $txt = $this->t('email.del_done', [
                    'email'          => htmlspecialchars($emailAddr),
                    'cpanel_status'  => $cpStatus,
                ]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_back_accounts'), 'callback_data' => 'sa_email_accounts']],
                ]])]);
                break;

            // ===== PROMPT BUAT AKUN EMAIL BARU =====
            case 'sa_email_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $domains = EmailManager::listDomains();
                $domainList   = '';
                $firstDomain  = '';
                foreach (array_slice($domains, 0, 5) as $d) {
                    $dn = $d['domain'] ?? (string)$d;
                    if (!$firstDomain) $firstDomain = $dn;
                    $domainList .= "• " . htmlspecialchars($dn) . "\n";
                }
                if (!$domainList) {
                    $cfg = EmailManager::getRuntimeConfig();
                    $firstDomain = preg_replace('/^webmail\./i', '', $cfg['mail_host']);
                    $domainList = "• " . htmlspecialchars($firstDomain) . "\n";
                }
                $txt = $this->t('email.create_choose', [
                    'domains' => $domainList,
                    'example' => $firstDomain,
                ]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_create_cpanel'), 'callback_data' => 'sa_email_create_via_cpanel'],
                     ['text' => $this->t('email.kb_create_manual'), 'callback_data' => 'sa_email_create_manual_prompt']],
                    [['text' => $this->t('email.kb_back'),          'callback_data' => 'sa_email_accounts']],
                ]])]);
                break;

            // ===== BUAT AKUN VIA CPANEL (prompt input) =====
            case 'sa_email_create_via_cpanel':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $domains = EmailManager::listDomains();
                $firstDomain = '';
                foreach (array_slice($domains, 0, 5) as $d) {
                    $dn = $d['domain'] ?? (string)$d;
                    if (!$firstDomain) $firstDomain = $dn;
                }
                if (!$firstDomain) {
                    $cfg = EmailManager::getRuntimeConfig();
                    $firstDomain = preg_replace('/^webmail\./i', '', $cfg['mail_host']);
                }
                $txt = $this->t('email.create_cpanel_prompt', ['example' => $firstDomain]);
                $r = self::send($this->chatId, $txt,
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_email_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== TAMBAH AKUN MANUAL (prompt input, tanpa cPanel) =====
            case 'sa_email_create_manual_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $txt = $this->t('email.create_manual_prompt');
                $r = self::send($this->chatId, $txt,
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_email_add_manual', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== SINKRONISASI DARI CPANEL =====
            case 'sa_email_sync_cpanel':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('email.sync_alert'));
                $cpAccounts = EmailManager::listAccounts();
                if (isset($cpAccounts['error'])) {
                    self::send($this->chatId, $this->t('email.sync_fail', ['error' => htmlspecialchars($cpAccounts['error'])]));
                    break;
                }
                $txt = $this->t('email.sync_header');
                if (empty($cpAccounts)) {
                    $txt .= $this->t('email.sync_empty');
                } else {
                    foreach ($cpAccounts as $i => $acc) {
                        $email  = htmlspecialchars($acc['email'] ?? ($acc['user'] . '@' . ($acc['domain'] ?? '')));
                        $quota  = $acc['_diskused'] ?? ($acc['diskused'] ?? '?');
                        $max    = $acc['_diskquota'] ?? ($acc['disklimit'] ?? '∞');
                        $txt   .= ($i + 1) . ". <code>{$email}</code>\n";
                        $txt   .= $this->t('email.sync_row', ['used' => $quota, 'max' => $max]);
                    }
                }
                $txt .= $this->t('email.sync_footer');
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_add_to_bot'), 'callback_data' => 'sa_email_create_prompt']],
                    [['text' => $this->t('email.kb_back'),       'callback_data' => 'sa_email_accounts']],
                ]])]);
                break;

            // ===== PENGATURAN SERVER EMAIL =====
            case 'sa_email_server_settings':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $cfg = EmailManager::getRuntimeConfig();
                $txt = $this->t('email.server_settings_txt', [
                    'cpanel_host' => $cfg['cpanel_host'],
                    'cpanel_port' => $cfg['cpanel_port'],
                    'cpanel_user' => $cfg['cpanel_user'] ?: '-',
                    'cpanel_pass' => $cfg['cpanel_pass'] ? str_repeat('*', min(8, strlen($cfg['cpanel_pass']))) : '-',
                    'mail_host'   => $cfg['mail_host'],
                    'imap_port'   => $cfg['imap_port'],
                    'smtp_port'   => $cfg['smtp_port'],
                ]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_edit_server'), 'callback_data' => 'sa_email_server_settings_edit']],
                    [['text' => $this->t('email.kb_back'),        'callback_data' => 'sa_email']],
                ]])]);
                break;

            case 'sa_email_server_settings_edit':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = $this->t('email.server_settings_prompt');
                $r = self::send($this->chatId, $promptTxt,
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_email_server_settings', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== INBOX =====
            case 'sa_email_inbox':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('email.inbox_alert'));
                $acctId = (int)$p1;
                $page   = (int)($p2 ?? 0);
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $perPage = 8;
                $inbox   = EmailManager::getInbox($ea['email'], $ea['email_password'], $perPage + ($page * $perPage));
                if (isset($inbox['error'])) {
                    self::edit($this->chatId, $msgId,
                        $this->t('email.inbox_fail', ['error' => htmlspecialchars($inbox['error'])]),
                        ['reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => $this->t('email.kb_back'), 'callback_data' => "sa_email_acct:{$acctId}"]],
                        ]])]);
                    break;
                }
                $slice = array_slice($inbox, $page * $perPage, $perPage);
                $total = count($inbox);
                $txt   = $this->t('email.inbox_header', [
                    'email' => htmlspecialchars($ea['email']),
                    'page'  => $page + 1,
                    'total' => $total,
                ]);
                if (empty($slice)) {
                    $txt .= $this->t('email.inbox_empty');
                } else {
                    foreach ($slice as $mail) {
                        $seen    = $mail['seen'] ? '' : '🔵 ';
                        $subject = htmlspecialchars(EmailManager::truncate($mail['subject'], 40));
                        $from    = htmlspecialchars(EmailManager::truncate($mail['from'], 30));
                        $txt    .= "{$seen}<b>{$subject}</b>\n";
                        $txt    .= "   ↩️ {$from}\n";
                        $txt    .= "   🕐 {$mail['date']}\n\n";
                    }
                }
                $rows = [];
                foreach ($slice as $mail) {
                    $subj  = EmailManager::truncate($mail['subject'], 32);
                    $rows[] = [['text' => ($mail['seen'] ? '' : '🔵 ') . $subj, 'callback_data' => "sa_email_read:{$acctId}:{$mail['uid']}"]];
                }
                $navRow = [];
                if ($page > 0)                       $navRow[] = ['text' => $this->t('email.kb_prev'), 'callback_data' => "sa_email_inbox:{$acctId}:" . ($page - 1)];
                if (($page + 1) * $perPage < $total) $navRow[] = ['text' => $this->t('email.kb_next'), 'callback_data' => "sa_email_inbox:{$acctId}:" . ($page + 1)];
                if ($navRow) $rows[] = $navRow;
                $rows[] = [
                    ['text' => $this->t('email.kb_send_email'), 'callback_data' => "sa_email_compose:{$acctId}"],
                    ['text' => $this->t('email.kb_refresh'),    'callback_data' => "sa_email_inbox:{$acctId}:0"],
                ];
                $rows[] = [['text' => $this->t('email.kb_back'), 'callback_data' => "sa_email_acct:{$acctId}"]];
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => $rows])]);
                break;

            // ===== BACA EMAIL =====
            case 'sa_email_read':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, $this->t('email.read_alert'));
                $acctId = (int)$p1;
                $uid    = (int)$p2;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $mail = EmailManager::readMail($ea['email'], $ea['email_password'], $uid);
                if (isset($mail['error'])) {
                    self::edit($this->chatId, $msgId,
                        $this->t('email.read_fail', ['error' => htmlspecialchars($mail['error'])]),
                        ['reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => $this->t('email.kb_back_inbox'), 'callback_data' => "sa_email_inbox:{$acctId}:0"]],
                        ]])]);
                    break;
                }
                $subject = htmlspecialchars($mail['subject']);
                $from    = htmlspecialchars($mail['from']);
                $to      = htmlspecialchars($mail['to']);
                $date    = htmlspecialchars($mail['date']);
                $body    = EmailManager::prepareBodyForTelegram(EmailManager::truncate($mail['body'], 2000));
                $txt     = "📨 <b>{$subject}</b>\n\n"
                         . "👤 Dari: {$from}\n"
                         . "📬 Ke: {$to}\n"
                         . "🕐 {$date}\n"
                         . "─────────────────\n"
                         . "{$body}";
                if (mb_strlen($txt) > 4000) $txt = mb_substr($txt, 0, 4000) . $this->t('email.truncated');
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => $this->t('email.kb_del_email'),  'callback_data' => "sa_email_delete:{$acctId}:{$uid}"],
                     ['text' => $this->t('email.kb_back_inbox'), 'callback_data' => "sa_email_inbox:{$acctId}:0"]],
                ]])]);
                break;

            // ===== HAPUS EMAIL =====
            case 'sa_email_delete':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $acctId = (int)$p1;
                $uid    = (int)$p2;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $ok = EmailManager::deleteMail($ea['email'], $ea['email_password'], $uid);
                self::answerCb($cbId, $ok ? $this->t('email.delete_ok_alert') : $this->t('email.delete_fail_alert'), !$ok);
                if ($ok) {
                    self::edit($this->chatId, $msgId,
                        $this->t('email.delete_done'),
                        ['reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => $this->t('email.kb_back_to_inbox'), 'callback_data' => "sa_email_inbox:{$acctId}:0"]],
                        ]])]);
                }
                break;

            // ===== COMPOSE EMAIL — Pilih Metode Pengiriman =====
            case 'sa_email_compose':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $acctId = (int)$p1;
                $ea     = $this->db->getEmailAccount($acctId);
                if (!$ea) { self::answerCb($cbId, $this->t('email.not_found_alert'), true); break; }
                $composeTxt = $this->t('email.compose_form', ['method' => $this->t('email.kb_webmail'), 'from' => $ea['email']]);
                $r = self::send($this->chatId, $composeTxt,
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_email_compose', $r['result']['message_id'] ?? 0, $msgId, ['acct_id' => $acctId, 'method' => 'webmail']);
                break;

            // ================================================================
            //  PANEL WHM — CALLBACK HANDLERS
            // ================================================================

            case 'sa_whm':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtWhmPanel(), ['reply_markup' => json_encode($this->kbWhmPanel())]);
                break;

            // ===== WHM: SUBMENU FTP =====
            case 'sa_whm_ftp':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "📂 <b>Kelola FTP — WHM</b>\n\nPilih operasi FTP yang ingin dilakukan:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbWhmFtp())]
                );
                break;

            // ===== WHM: TES KONEKSI =====
            case 'sa_whm_test':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Menghubungi WHM server...');
                $res = WhmManager::testConnection();
                if ($res['success']) {
                    $txt = "✅ <b>WHM Terhubung!</b>\n\n🖥️ <b>Versi WHM:</b> <code>" . htmlspecialchars($res['version']) . "</code>";
                } else {
                    $txt = "❌ <b>Gagal Terhubung ke WHM</b>\n\n<i>" . htmlspecialchars($res['message']) . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Tes Ulang', 'callback_data' => 'sa_whm_test']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ===== WHM: INFO SERVER =====
            case 'sa_whm_serverinfo':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil info server...');
                $info = WhmManager::serverInfo();
                if (isset($info['error'])) {
                    $txt = "❌ <b>Gagal mengambil info server.</b>\n\n<i>" . htmlspecialchars($info['error']) . "</i>";
                } else {
                    $txt = "🖥️ <b>Informasi WHM Server</b>\n\n"
                         . "🌐 <b>Hostname:</b> <code>" . htmlspecialchars($info['hostname']) . "</code>\n"
                         . "📦 <b>Versi WHM:</b> <code>" . htmlspecialchars($info['version']) . "</code>\n"
                         . "📊 <b>Load Average:</b>\n"
                         . "  • 1 menit: <code>" . htmlspecialchars((string)$info['load1']) . "</code>\n"
                         . "  • 5 menit: <code>" . htmlspecialchars((string)$info['load5']) . "</code>\n"
                         . "  • 15 menit: <code>" . htmlspecialchars((string)$info['load15']) . "</code>\n"
                         . "\n🕒 <i>Diperbarui: " . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_serverinfo']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ===== WHM: DAFTAR AKUN =====
            case 'sa_whm_accounts':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar akun...');
                $accounts = WhmManager::listAccounts(20);
                if (isset($accounts['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar akun.</b>\n\n<i>" . htmlspecialchars($accounts['error']) . "</i>";
                    self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                    ]])]);
                    break;
                }
                if (empty($accounts)) {
                    $txt = "📭 <b>Tidak ada akun cPanel.</b>";
                } else {
                    $txt = "👥 <b>Daftar Akun cPanel</b> (" . count($accounts) . " akun)\n\n";
                    foreach ($accounts as $i => $acct) {
                        $user   = htmlspecialchars($acct['user'] ?? '-');
                        $domain = htmlspecialchars($acct['domain'] ?? '-');
                        $susp   = ($acct['suspended'] ?? 0) ? ' ⛔' : '';
                        $txt   .= ($i + 1) . ". <code>{$user}</code> — {$domain}{$susp}\n";
                    }
                    $txt .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_accounts']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ===== WHM: DAFTAR FTP (untuk akun cPanel tertentu) =====
            case 'sa_whm_ftp_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar FTP...');
                $cpUser  = trim($p1 ?? '');
                if (!$cpUser) {
                    self::edit($this->chatId, $msgId, "⚠️ User cPanel tidak ditentukan.", ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                    ]])]);
                    break;
                }
                $ftpList = WhmManager::ftpList($cpUser);
                if (isset($ftpList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar FTP untuk <code>" . htmlspecialchars($cpUser) . "</code></b>\n\n<i>" . htmlspecialchars($ftpList['error']) . "</i>";
                } elseif (empty($ftpList)) {
                    $txt = "📭 <b>Tidak ada akun FTP</b> untuk <code>" . htmlspecialchars($cpUser) . "</code>.";
                } else {
                    $txt = "📋 <b>Daftar FTP — " . htmlspecialchars($cpUser) . "</b>\n\n";
                    foreach ($ftpList as $i => $ftp) {
                        $login  = htmlspecialchars($ftp['login'] ?? $ftp['user'] ?? '-');
                        $dir    = htmlspecialchars($ftp['dir'] ?? $ftp['homedir'] ?? '-');
                        $diskUsed  = $ftp['diskused'] ?? $ftp['_diskused'] ?? '-';
                        $diskQuota = $ftp['diskquota'] ?? $ftp['_diskquota'] ?? '∞';
                        $txt   .= ($i + 1) . ". <code>{$login}</code>\n";
                        $txt   .= "   📁 <code>{$dir}</code>\n";
                        $txt   .= "   💾 {$diskUsed} / {$diskQuota} MB\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh',   'callback_data' => "sa_whm_ftp_list:{$cpUser}"]],
                    [['text' => '🔙 Menu FTP',  'callback_data' => 'sa_whm_ftp']],
                ]])]);
                break;

            // ===== WHM: PROMPT BUAT FTP =====
            case 'sa_whm_ftp_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "➕ <b>Buat Akun FTP via WHM</b>\n\n"
                           . "Kirimkan data FTP dalam satu baris:\n"
                           . "<code>cpanel_user ftp_user password [homedir] [quota_mb]</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>myhosting john secret123</code>\n"
                           . "<code>myhosting john secret123 /home/myhosting/ftpjohn 100</code>\n\n"
                           . "ℹ️ <i>homedir dan quota bersifat opsional. Quota 0 = unlimited.</i>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_whm_ftp']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_ftp_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  WHM: DNS MANAGEMENT
            // ================================================================

            case 'sa_whm_dns':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "🌐 <b>Manajemen DNS — WHM</b>\n\nKelola DNS zone dan record di server ini.\n\n📌 <b>Tip hapus record:</b> Gunakan menu <i>Lihat Zone</i> untuk melihat nomor baris tiap record.",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbWhmDns())]);
                break;

            // ===== WHM: DAFTAR SEMUA ZONE =====
            case 'sa_whm_dns_zones':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar zone DNS...');
                $zones = WhmManager::dnsListZones();
                if (isset($zones['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar zone.</b>\n\n<i>" . htmlspecialchars($zones['error']) . "</i>";
                } elseif (empty($zones)) {
                    $txt = "📭 <b>Tidak ada DNS zone di server ini.</b>";
                } else {
                    $txt = "🗂️ <b>Daftar DNS Zone</b> (" . count($zones) . " zone)\n\n";
                    foreach (array_slice($zones, 0, 50) as $i => $z) {
                        $name = htmlspecialchars($z['zoneName'] ?? $z['domain'] ?? '-');
                        $txt .= ($i + 1) . ". <code>{$name}</code>\n";
                    }
                    if (count($zones) > 50) $txt .= "\n<i>...dan " . (count($zones) - 50) . " zone lainnya.</i>";
                    $txt .= "\n\n💡 Ketuk <b>Lihat Zone</b> untuk melihat record detail suatu domain.";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_dns_zones']],
                    [['text' => '📂 Lihat Zone', 'callback_data' => 'sa_whm_dns_zone_prompt']],
                    [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                ]])]);
                break;

            // ===== WHM: LIHAT RECORD DNS ZONE (via callback dengan p1=domain) =====
            case 'sa_whm_dns_zone':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $dnsDom = strtolower(trim($p1 ?? ''));
                if (!$dnsDom) {
                    self::answerCb($cbId, '⚠️ Domain tidak ditentukan.', true);
                    break;
                }
                self::answerCb($cbId, '⏳ Mengambil DNS record...');
                $records = WhmManager::dnsGetZone($dnsDom);
                if (isset($records['error'])) {
                    $txt = "❌ <b>Gagal mengambil zone <code>" . htmlspecialchars($dnsDom) . "</code></b>\n\n<i>" . htmlspecialchars($records['error']) . "</i>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]];
                } elseif (empty($records)) {
                    $txt = "📭 <b>Tidak ada record di zone <code>" . htmlspecialchars($dnsDom) . "</code>.</b>";
                    $kb  = ['inline_keyboard' => [[['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']]]];
                } else {
                    $txt = "🗂️ <b>DNS Zone: <code>" . htmlspecialchars($dnsDom) . "</code></b>\n\n";
                    $count = 0;
                    foreach ($records as $rec) {
                        $type = $rec['type'] ?? '';
                        if (in_array($type, ['SOA', 'NS', ''])) continue;
                        $line = $rec['Line'] ?? ($rec['line'] ?? '-');
                        $name = htmlspecialchars($rec['name'] ?? '@');
                        $val  = htmlspecialchars($rec['address'] ?? $rec['cname'] ?? $rec['exchange'] ?? $rec['nsdname'] ?? $rec['txtdata'] ?? '-');
                        $ttl  = $rec['ttl'] ?? '';
                        $prio = ($rec['preference'] ?? $rec['priority'] ?? null) !== null
                            ? " (prio:" . ($rec['preference'] ?? $rec['priority']) . ")" : '';
                        $txt .= "• [<code>{$line}</code>] <b>{$type}</b> {$name} → <code>{$val}</code>{$prio} <i>TTL:{$ttl}</i>\n";
                        if (++$count >= 40) { $txt .= "\n<i>...terlalu banyak record. Cek WHM panel untuk melihat semua.</i>"; break; }
                    }
                    $kb = ['inline_keyboard' => [
                        [['text' => '➕ Tambah Record', 'callback_data' => 'sa_whm_dns_add_prompt'],
                         ['text' => '🗑️ Hapus Record',  'callback_data' => 'sa_whm_dns_del_prompt']],
                        [['text' => '♻️ Reset Zone',    'callback_data' => "sa_whm_dns_reset_prompt:{$dnsDom}"]],
                        [['text' => '🔄 Refresh',       'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                        [['text' => '🔙 Menu DNS',       'callback_data' => 'sa_whm_dns']],
                    ]];
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode($kb)]);
                break;

            // ===== WHM: PROMPT INPUT DOMAIN UNTUK LIHAT ZONE =====
            case 'sa_whm_dns_zone_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🔍 <b>Lihat DNS Zone</b>\n\nKirimkan nama domain yang ingin dilihat recordnya:\n\n<b>Contoh:</b>\n<code>example.com</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_whm_dns']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_dns_zone_input', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT TAMBAH DNS RECORD =====
            case 'sa_whm_dns_add_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Tambah DNS Record</b>\n\nKirimkan dalam satu baris:\n<code>domain TIPE nama nilai [ttl] [prioritas]</code>\n\n<b>Tipe yang didukung:</b> A, AAAA, CNAME, MX, TXT, NS\n\n<b>Contoh:</b>\n<code>example.com A www 1.2.3.4</code>\n<code>example.com CNAME mail example.com.</code>\n<code>example.com MX @ mail.example.com. 14400 10</code>\n<code>example.com TXT @ \"v=spf1 include:sendgrid.net ~all\"</code>\n\nℹ️ <i>TTL default: 14400 | Prioritas MX default: 10</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_whm_dns']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_dns_add', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT HAPUS DNS RECORD =====
            case 'sa_whm_dns_del_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus DNS Record</b>\n\nKirimkan dalam satu baris:\n<code>domain nomor_baris</code>\n\n<b>Contoh:</b>\n<code>example.com 12</code>\n\nℹ️ <i>Nomor baris dapat dilihat di menu <b>Lihat Zone</b> (angka dalam kurung kotak [<b>n</b>]).</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '📂 Lihat Zone Dulu', 'callback_data' => 'sa_whm_dns_zone_prompt']],
                        [['text' => '❌ Batal', 'callback_data' => 'sa_whm_dns']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_dns_del', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: KONFIRMASI RESET DNS ZONE (p1=domain, atau minta input domain jika kosong) =====
            case 'sa_whm_dns_reset_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $dnsDom = strtolower(trim($p1 ?? ''));
                self::answerCb($cbId);
                if (!$dnsDom) {
                    $r = self::send($this->chatId,
                        "♻️ <b>Reset DNS Zone</b>\n\nKirimkan nama domain yang ingin direset:\n\n<b>Contoh:</b>\n<code>example.com</code>\n\n⚠️ <i>Aksi ini akan menghapus semua record kustom!</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '❌ Batal', 'callback_data' => 'sa_whm_dns']],
                        ]])]);
                    $this->db->setPendingInput($this->userId, 0, 'sa_whm_dns_reset_input', $r['result']['message_id'] ?? 0, $msgId);
                    break;
                }
                self::edit($this->chatId, $msgId,
                    "⚠️ <b>Konfirmasi Reset DNS Zone</b>\n\n🌐 Domain: <code>" . htmlspecialchars($dnsDom) . "</code>\n\n⚠️ <b>Aksi ini akan menghapus SEMUA record kustom</b> dan mengembalikan zone ke konfigurasi default cPanel. Lanjutkan?",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '✅ Ya, Reset Sekarang', 'callback_data' => "sa_whm_dns_reset_yes:{$dnsDom}"]],
                        [['text' => '❌ Batal', 'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                    ]])]);
                break;

            // ===== WHM: EKSEKUSI RESET DNS ZONE =====
            case 'sa_whm_dns_reset_yes':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                $dnsDom = strtolower(trim($p1 ?? ''));
                if (!$dnsDom) { self::answerCb($cbId, '⚠️ Domain tidak valid.', true); break; }
                self::answerCb($cbId, '⏳ Mereset DNS zone...');
                $res = WhmManager::dnsResetZone($dnsDom);
                if ($res['success'] ?? false) {
                    self::edit($this->chatId, $msgId,
                        "✅ <b>DNS Zone <code>" . htmlspecialchars($dnsDom) . "</code> berhasil direset ke default.</b>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Lihat Zone', 'callback_data' => "sa_whm_dns_zone:{$dnsDom}"]],
                            [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                        ]])]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal reset zone.');
                    self::edit($this->chatId, $msgId,
                        "❌ <b>Gagal reset DNS zone.</b>\n\n<i>{$err}</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔙 Menu DNS', 'callback_data' => 'sa_whm_dns']],
                        ]])]);
                }
                break;

            // ===== WHM: SETTINGS — VIEW =====
            case 'sa_whm_settings_view':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $cfg = WhmManager::getRuntimeConfig();
                $txt = "⚙️ <b>Settings WHM</b>\n\n"
                     . "🌐 <b>Host:</b> <code>" . htmlspecialchars($cfg['whm_host']) . "</code>\n"
                     . "🔌 <b>Port:</b> <code>" . $cfg['whm_port'] . "</code>\n"
                     . "👤 <b>User:</b> <code>" . ($cfg['whm_user'] ?: '❌ Belum diisi') . "</code>\n"
                     . "🔑 <b>Token:</b> " . ($cfg['whm_token'] ? '✅ Tersimpan' : '❌ Kosong') . "\n"
                     . "🔒 <b>Pass:</b> "  . ($cfg['whm_pass']  ? '✅ Tersimpan' : '❌ Kosong') . "\n\n"
                     . "ℹ️ <i>Disarankan gunakan API Token daripada password.</i>";
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '✏️ Edit Settings', 'callback_data' => 'sa_whm_settings_edit']],
                    [['text' => '🔙 Panel WHM',     'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ===== WHM: SETTINGS — EDIT =====
            case 'sa_whm_settings_edit':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "⚙️ <b>Edit Settings WHM</b>\n\n"
                           . "Kirim data per baris:\n"
                           . "<code>hostname\nport\nusername\napi_token\npassword</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>server.example.com\n2087\nroot\nMY_API_TOKEN\n</code>\n\n"
                           . "ℹ️ <i>Kosongkan baris yang tidak ingin diubah. "
                           . "Gunakan token (baris ke-4) atau password (baris ke-5), tidak perlu keduanya.</i>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_settings', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  PANEL CPANEL — CALLBACK HANDLERS
            // ================================================================

            case 'sa_cpanel':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId, $this->txtCpanelPanel(), ['reply_markup' => json_encode($this->kbCpanelPanel())]);
                break;

            // ===== CPANEL: SUBMENU FTP =====
            case 'sa_cpanel_ftp':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "📂 <b>Kelola FTP — cPanel</b>\n\nPilih operasi FTP yang ingin dilakukan:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbCpanelFtp())]
                );
                break;

            // ===== CPANEL: TES KONEKSI =====
            case 'sa_cpanel_test':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Menghubungi cPanel server...');
                $res = CpanelManager::testConnection();
                if ($res['success']) {
                    $txt = "✅ <b>cPanel Terhubung!</b>\n\n"
                         . "🌐 <b>Hostname:</b> <code>" . htmlspecialchars($res['hostname']) . "</code>\n"
                         . "📦 <b>Versi:</b> <code>" . htmlspecialchars($res['version']) . "</code>";
                } else {
                    $txt = "❌ <b>Gagal Terhubung ke cPanel</b>\n\n<i>" . htmlspecialchars($res['message']) . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Tes Ulang', 'callback_data' => 'sa_cpanel_test']],
                    [['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']],
                ]])]);
                break;

            // ===== CPANEL: DAFTAR FTP =====
            case 'sa_cpanel_ftp_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar FTP...');
                $ftpList = CpanelManager::ftpList();
                if (isset($ftpList['error'])) {
                    $cfg = CpanelManager::getRuntimeConfig();
                    $hintKonfigurasi = (!$cfg['cpanel_host'] || !$cfg['cpanel_user'] || !$cfg['cpanel_pass'])
                        ? "\n\n⚠️ <i>Periksa Settings cPanel — host/user/password belum lengkap.</i>"
                        : "\n\n⚠️ <i>Pastikan kredensial cPanel benar dan server dapat diakses.</i>";
                    $txt = "❌ <b>Gagal mengambil daftar FTP.</b>\n\n<i>" . htmlspecialchars($ftpList['error']) . "</i>" . $hintKonfigurasi;
                    self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '🔄 Coba Lagi',      'callback_data' => 'sa_cpanel_ftp_list']],
                        [['text' => '⚙️ Settings cPanel', 'callback_data' => 'sa_cpanel_settings_view']],
                        [['text' => '🔙 Menu FTP',        'callback_data' => 'sa_cpanel_ftp']],
                    ]])]);
                    break;
                }
                if (empty($ftpList)) {
                    $txt = "📭 <b>Tidak ada akun FTP.</b>\n\n<i>Belum ada akun FTP yang dibuat di cPanel ini.</i>";
                } else {
                    $txt = "📋 <b>Daftar Akun FTP cPanel</b> (" . count($ftpList) . " akun)\n\n";
                    foreach ($ftpList as $i => $ftp) {
                        $login     = htmlspecialchars($ftp['login'] ?? $ftp['user'] ?? '-');
                        $dir       = htmlspecialchars($ftp['dir'] ?? $ftp['homedir'] ?? '-');
                        $diskUsed  = $ftp['diskused']  ?? $ftp['_diskused']  ?? '-';
                        $diskQuota = $ftp['diskquota'] ?? $ftp['_diskquota'] ?? '∞';
                        $txt .= ($i + 1) . ". <code>{$login}</code>\n";
                        $txt .= "   📁 <code>{$dir}</code>\n";
                        $txt .= "   💾 {$diskUsed} / {$diskQuota} MB\n\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh',       'callback_data' => 'sa_cpanel_ftp_list']],
                    [['text' => '🔙 Menu FTP',      'callback_data' => 'sa_cpanel_ftp']],
                ]])]);
                break;

            // ===== CPANEL: PROMPT BUAT FTP =====
            case 'sa_cpanel_ftp_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "➕ <b>Buat Akun FTP baru</b>\n\n"
                           . "Kirimkan data FTP dalam satu baris:\n"
                           . "<code>ftp_user password [homedir] [quota_mb]</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>john secret123</code>\n"
                           . "<code>john secret123 /home/user/ftpjohn 200</code>\n\n"
                           . "ℹ️ <i>homedir dan quota bersifat opsional. Quota 0 = unlimited.</i>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_ftp']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_ftp_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT HAPUS FTP =====
            case 'sa_cpanel_ftp_delete_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "🗑️ <b>Hapus Akun FTP</b>\n\n"
                           . "Kirimkan dalam satu baris:\n"
                           . "<code>ftp_username domain.com</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>john example.com</code>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_ftp']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_ftp_delete', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT UBAH PASSWORD FTP =====
            case 'sa_cpanel_ftp_passwd_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "🔑 <b>Ubah Password FTP</b>\n\n"
                           . "Kirimkan dalam satu baris:\n"
                           . "<code>ftp_username domain.com password_baru</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>john example.com newpassword123</code>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_ftp']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_ftp_passwd', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT ATUR QUOTA FTP =====
            case 'sa_cpanel_ftp_quota_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "📦 <b>Atur Quota FTP</b>\n\n"
                           . "Kirimkan dalam satu baris:\n"
                           . "<code>ftp_username domain.com quota_mb</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>john example.com 500</code>\n"
                           . "<code>john example.com 0</code> (unlimited)\n\n"
                           . "ℹ️ <i>Quota dalam satuan MB. Gunakan 0 untuk unlimited.</i>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_ftp']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_ftp_quota', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: SETTINGS — VIEW =====
            case 'sa_cpanel_settings_view':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $cfg = CpanelManager::getRuntimeConfig();
                $txt = "⚙️ <b>Settings cPanel</b>\n\n"
                     . "🌐 <b>Host:</b> <code>" . htmlspecialchars($cfg['cpanel_host']) . "</code>\n"
                     . "🔌 <b>Port:</b> <code>" . $cfg['cpanel_port'] . "</code>\n"
                     . "👤 <b>User:</b> <code>" . ($cfg['cpanel_user'] ?: '❌ Belum diisi') . "</code>\n"
                     . "🔒 <b>Pass:</b> "  . ($cfg['cpanel_pass']  ? '✅ Tersimpan' : '❌ Kosong');
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '✏️ Edit Settings', 'callback_data' => 'sa_cpanel_settings_edit']],
                    [['text' => '🔙 Panel cPanel',  'callback_data' => 'sa_cpanel']],
                ]])]);
                break;

            // ===== CPANEL: SETTINGS — EDIT =====
            case 'sa_cpanel_settings_edit':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $promptTxt = "⚙️ <b>Edit Settings cPanel</b>\n\n"
                           . "Kirim data per baris:\n"
                           . "<code>hostname\nport\nusername\npassword</code>\n\n"
                           . "<b>Contoh:</b>\n"
                           . "<code>cpanel.example.com\n2083\nmyuser\nmypassword</code>\n\n"
                           . "ℹ️ <i>Kosongkan baris yang tidak ingin diubah.</i>";
                $r = self::send($this->chatId, $promptTxt, ['parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cancel_input']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_settings', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  WHM: MANAJEMEN AKUN (submenu)
            // ================================================================

            case 'sa_whm_acct':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "⚙️ <b>Kelola Akun cPanel</b>\n\nPilih operasi manajemen akun:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbWhmAcct())]
                );
                break;

            // ===== WHM: PROMPT SUSPEND =====
            case 'sa_whm_suspend_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "⛔ <b>Suspend Akun cPanel</b>\n\nKirimkan:\n<code>username [alasan_suspend]</code>\n\n<b>Contoh:</b>\n<code>john Melanggar aturan</code>\n\n<i>Alasan bersifat opsional.</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_suspend', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT UNSUSPEND =====
            case 'sa_whm_unsuspend_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "✅ <b>Unsuspend Akun cPanel</b>\n\nKirimkan username akun yang ingin di-unsuspend:\n<code>username</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_unsuspend', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT BUAT AKUN CPANEL =====
            case 'sa_whm_create_acct_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Buat Akun cPanel Baru</b>\n\nKirimkan data per baris:\n"
                    . "<code>username\ndomain.com\npassword\nplan_name (opsional)\nemail@admin.com (opsional)</code>\n\n"
                    . "<b>Contoh:</b>\n<code>john\nexample.com\nsecret123</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_create_acct', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT HAPUS AKUN CPANEL (langkah 1) =====
            case 'sa_whm_remove_acct_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus Akun cPanel</b>\n\n⚠️ <b>Aksi ini PERMANEN dan tidak dapat dibatalkan!</b>\n\nKirimkan username akun yang ingin dihapus:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_remove_acct_get', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: KONFIRMASI HAPUS AKUN (langkah 2 — via callback) =====
            case 'sa_whm_remove_yes':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                if (!$p1) { self::answerCb($cbId, 'Username tidak valid.', true); break; }
                self::answerCb($cbId, '⏳ Menghapus akun...');
                $res = WhmManager::removeAccount($p1);
                if ($res['success'] ?? false) {
                    self::edit($this->chatId, $msgId,
                        "✅ <b>Akun <code>" . htmlspecialchars($p1) . "</code> berhasil dihapus.</b>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '👥 Daftar Akun', 'callback_data' => 'sa_whm_accounts'],
                             ['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                        ]])]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus akun.');
                    self::edit($this->chatId, $msgId,
                        "❌ <b>Gagal menghapus akun.</b>\n\n<i>{$err}</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔙 Kelola Akun', 'callback_data' => 'sa_whm_acct']],
                        ]])]);
                }
                break;

            // ===== WHM: PROMPT UBAH PASSWORD AKUN =====
            case 'sa_whm_chpass_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🔑 <b>Ubah Password Akun cPanel</b>\n\nKirimkan dalam satu baris:\n<code>username password_baru</code>\n\n<b>Contoh:</b>\n<code>john newsecret456</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_chpass', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: RESTART SERVICES (tampilkan pilihan) =====
            case 'sa_whm_restart_services':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                self::edit($this->chatId, $msgId,
                    "🔄 <b>Restart Layanan WHM</b>\n\nPilih layanan yang ingin direstart:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '🌐 Apache',    'callback_data' => 'sa_whm_restart:apache'],
                         ['text' => '🗄️ MySQL',     'callback_data' => 'sa_whm_restart:mysql']],
                        [['text' => '📧 Exim',       'callback_data' => 'sa_whm_restart:exim'],
                         ['text' => '🌍 DNS (named)', 'callback_data' => 'sa_whm_restart:named']],
                        [['text' => '📁 FTP',        'callback_data' => 'sa_whm_restart:ftpserver'],
                         ['text' => '⚙️ cPSrvd',     'callback_data' => 'sa_whm_restart:cpsrvd']],
                        [['text' => '🔙 Panel WHM',  'callback_data' => 'sa_whm']],
                    ]])]);
                break;

            // ===== WHM: EKSEKUSI RESTART SERVICE =====
            case 'sa_whm_restart':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                if (!$p1) { self::answerCb($cbId, 'Service tidak valid.', true); break; }
                self::answerCb($cbId, "⏳ Merestart {$p1}...");
                $res = WhmManager::restartService($p1);
                if ($res['success'] ?? false) {
                    $out = !empty($res['output']) ? "\n\n<code>" . htmlspecialchars(substr($res['output'], 0, 300)) . "</code>" : '';
                    self::edit($this->chatId, $msgId,
                        "✅ <b>Service <code>" . htmlspecialchars($p1) . "</code> berhasil direstart.</b>{$out}",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔄 Restart Lagi', 'callback_data' => "sa_whm_restart:{$p1}"],
                             ['text' => '🔙 Pilih Layanan', 'callback_data' => 'sa_whm_restart_services']],
                        ]])]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal restart service.');
                    self::edit($this->chatId, $msgId,
                        "❌ <b>Gagal restart <code>" . htmlspecialchars($p1) . "</code>.</b>\n\n<i>{$err}</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔄 Coba Lagi', 'callback_data' => "sa_whm_restart:{$p1}"],
                             ['text' => '🔙 Pilih Layanan', 'callback_data' => 'sa_whm_restart_services']],
                        ]])]);
                }
                break;

            // ===== WHM: BANDWIDTH =====
            case 'sa_whm_bandwidth':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil data bandwidth...');
                $bwList = WhmManager::getBandwidth();
                if (isset($bwList['error'])) {
                    $txt = "❌ <b>Gagal mengambil data bandwidth.</b>\n\n<i>" . htmlspecialchars($bwList['error']) . "</i>";
                } elseif (empty($bwList)) {
                    $txt = "📊 <b>Bandwidth WHM</b>\n\n<i>Tidak ada data bandwidth.</i>";
                } else {
                    $txt = "📊 <b>Bandwidth WHM</b> (Top " . count($bwList) . " akun)\n\n";
                    foreach ($bwList as $i => $acct) {
                        $user   = htmlspecialchars($acct['acct'] ?? $acct['user'] ?? '-');
                        $bytes  = (int)($acct['totalbytes'] ?? 0);
                        $limit  = (int)($acct['limit'] ?? 0);
                        $used   = $bytes >= 1073741824
                            ? round($bytes / 1073741824, 2) . ' GB'
                            : round($bytes / 1048576, 1) . ' MB';
                        $lim    = $limit === 0 ? '∞'
                            : ($limit >= 1073741824
                                ? round($limit / 1073741824, 2) . ' GB'
                                : round($limit / 1048576, 1) . ' MB');
                        $txt .= ($i + 1) . ". <code>{$user}</code> — <b>{$used}</b> / {$lim}\n";
                    }
                    $txt .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_bandwidth']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ================================================================
            //  WHM: PACKAGE / PLAN MANAGEMENT
            // ================================================================

            case 'sa_whm_pkg':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "📦 <b>Manajemen Package WHM</b>\n\nKelola paket hosting untuk akun cPanel.\nPilih operasi:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbWhmPkg())]);
                break;

            // ===== WHM: DAFTAR PACKAGE =====
            case 'sa_whm_pkg_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar package...');
                $pkgList = WhmManager::listPackages();
                if (isset($pkgList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar package.</b>\n\n<i>" . htmlspecialchars($pkgList['error']) . "</i>";
                } elseif (empty($pkgList)) {
                    $txt = "📭 <b>Tidak ada package yang tersedia.</b>";
                } else {
                    $txt = "📦 <b>Daftar Package WHM</b> (" . count($pkgList) . " package)\n\n";
                    foreach ($pkgList as $i => $pkg) {
                        $name    = htmlspecialchars($pkg['name'] ?? '-');
                        $quota   = ($pkg['quota']   ?? 0) == 0 ? '∞' : ($pkg['quota'] . ' MB');
                        $bwlimit = ($pkg['bwlimit'] ?? 0) == 0 ? '∞' : ($pkg['bwlimit'] . ' MB');
                        $maxftp  = ($pkg['maxftp']  ?? 0) == 0 ? '∞' : ($pkg['maxftp'] ?? '∞');
                        $maxsql  = ($pkg['maxsql']  ?? 0) == 0 ? '∞' : ($pkg['maxsql'] ?? '∞');
                        $maxpop  = ($pkg['maxpop']  ?? 0) == 0 ? '∞' : ($pkg['maxpop'] ?? '∞');
                        $txt .= ($i + 1) . ". <b>" . $name . "</b>\n"
                             . "   💾 Disk: {$quota} | 📈 BW: {$bwlimit}\n"
                             . "   📂 FTP: {$maxftp} | 🗄️ SQL: {$maxsql} | 📧 POP: {$maxpop}\n\n";
                        if ($i >= 19) { $txt .= "<i>...dan lebih banyak.</i>\n"; break; }
                    }
                    $txt .= "🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh',    'callback_data' => 'sa_whm_pkg_list']],
                    [['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']],
                ]])]);
                break;

            // ===== WHM: PROMPT BUAT PACKAGE =====
            case 'sa_whm_pkg_add_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Buat Package WHM Baru</b>\n\nKirimkan data per baris:\n"
                    . "<code>nama_package\nquota_mb\nbwlimit_mb\nmaxftp\nmaxsql\nmaxpop</code>\n\n"
                    . "<b>Contoh:</b>\n<code>Starter\n1024\n5120\n10\n5\n10</code>\n\n"
                    . "ℹ️ <i>Gunakan 0 untuk unlimited. Baris 2-6 opsional (default: 0).</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_pkg']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_pkg_add', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT HAPUS PACKAGE =====
            case 'sa_whm_pkg_del_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $pkgList2 = WhmManager::listPackages();
                if (isset($pkgList2['error']) || empty($pkgList2)) {
                    self::edit($this->chatId, $msgId, "⚠️ Tidak ada package yang bisa dihapus.", ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']]]])]);
                    break;
                }
                $txt = "🗑️ <b>Hapus Package WHM</b>\n\nPilih package yang ingin dihapus:";
                $kb2 = [];
                foreach ($pkgList2 as $pkg) {
                    $pName = $pkg['name'] ?? '';
                    if (!$pName) continue;
                    $kb2[] = [['text' => "🗑️ " . htmlspecialchars($pName), 'callback_data' => "sa_whm_pkg_del_yes:" . urlencode($pName)]];
                }
                $kb2[] = [['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']];
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb2])]);
                break;

            // ===== WHM: KONFIRMASI & HAPUS PACKAGE =====
            case 'sa_whm_pkg_del_yes':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                if (!$p1) { self::answerCb($cbId, 'Nama package tidak valid.', true); break; }
                $pkgToDelete = urldecode($p1);
                self::answerCb($cbId, "⏳ Menghapus package {$pkgToDelete}...");
                $res = WhmManager::removePackage($pkgToDelete);
                if ($res['success'] ?? false) {
                    self::edit($this->chatId, $msgId,
                        "✅ Package <code>" . htmlspecialchars($pkgToDelete) . "</code> berhasil dihapus.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '📋 Daftar Package', 'callback_data' => 'sa_whm_pkg_list'],
                             ['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']],
                        ]])]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menghapus package.');
                    self::edit($this->chatId, $msgId,
                        "❌ <b>Gagal menghapus package.</b>\n\n<i>{$err}</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 Package', 'callback_data' => 'sa_whm_pkg']]]])]);
                }
                break;

            // ================================================================
            //  WHM: DISK INFO SERVER
            // ================================================================

            case 'sa_whm_diskinfo':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil info disk...');
                // getDiskInfo() mengembalikan array partisi disk (disk_info[]) atau ['error'=>...]
                // Setiap partisi: disk, mount, blocks, blocks_used, blocks_free, blocks_percent_used (1KB blocks)
                $diskPartitions = WhmManager::getDiskInfo();
                if (isset($diskPartitions['error'])) {
                    $txt = "❌ <b>Gagal mengambil info disk.</b>\n\n<i>" . htmlspecialchars($diskPartitions['error']) . "</i>";
                } else {
                    $fmtKb = function(int $kb): string {
                        if ($kb >= 1073741824) return round($kb / 1073741824, 2) . ' PB';
                        if ($kb >= 1048576)    return round($kb / 1048576, 2) . ' TB';
                        if ($kb >= 1024)       return round($kb / 1024, 2) . ' GB';
                        return $kb . ' MB';
                    };
                    $txt = "💾 <b>Info Disk Server WHM</b> (" . count($diskPartitions) . " partisi)\n\n";
                    foreach ($diskPartitions as $i => $part) {
                        $mount   = htmlspecialchars($part['mount']              ?? '/');
                        $device  = htmlspecialchars($part['disk']               ?? '-');
                        $total   = (int)($part['blocks']               ?? 0);
                        $used    = (int)($part['blocks_used']          ?? 0);
                        $free    = (int)($part['blocks_free']          ?? 0);
                        $pct     = (int)($part['blocks_percent_used']  ?? ($total > 0 ? round($used / $total * 100) : 0));
                        $barFill = min(10, (int)round($pct / 10));
                        $bar     = str_repeat('█', $barFill) . str_repeat('░', 10 - $barFill);
                        $txt    .= "📂 <b>" . $mount . "</b> <i>(" . $device . ")</i>\n"
                                 . "   📊 Total:    <code>" . $fmtKb($total) . "</code>\n"
                                 . "   🔴 Terpakai: <code>" . $fmtKb($used)  . "</code>\n"
                                 . "   🟢 Tersedia: <code>" . $fmtKb($free)  . "</code>\n"
                                 . "   📈 [{$bar}] <b>{$pct}%</b>\n\n";
                        if ($i >= 9) { $txt .= "<i>...dan lebih banyak partisi.</i>\n\n"; break; }
                    }
                    $txt .= "🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_diskinfo']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ================================================================
            //  WHM: MANAJEMEN IP
            // ================================================================

            case 'sa_whm_ips':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar IP...');
                $ipList = WhmManager::listIPs();
                if (isset($ipList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar IP.</b>\n\n<i>" . htmlspecialchars($ipList['error']) . "</i>";
                } elseif (empty($ipList)) {
                    $txt = "📭 <b>Tidak ada IP yang terdaftar.</b>";
                } else {
                    $txt = "🌐 <b>Daftar IP Server WHM</b> (" . count($ipList) . " IP)\n\n";
                    foreach ($ipList as $i => $ip) {
                        $addr    = htmlspecialchars($ip['ip']     ?? $ip['address'] ?? '-');
                        $status  = htmlspecialchars($ip['status'] ?? 'N/A');
                        $user    = htmlspecialchars($ip['user']   ?? '-');
                        $domain  = htmlspecialchars($ip['domain'] ?? '-');
                        $txt    .= ($i + 1) . ". <code>{$addr}</code> — {$status}\n";
                        if ($user !== '-')  $txt .= "   👤 {$user}";
                        if ($domain !== '-') $txt .= " | 🌐 {$domain}";
                        $txt .= "\n";
                        if ($i >= 29) { $txt .= "\n<i>...dan lebih banyak.</i>"; break; }
                    }
                    $txt .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh',    'callback_data' => 'sa_whm_ips']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ================================================================
            //  WHM: MANAJEMEN RESELLER
            // ================================================================

            case 'sa_whm_reseller':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "👔 <b>Manajemen Reseller WHM</b>\n\nKelola hak reseller untuk akun cPanel.\nPilih operasi:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbWhmReseller())]);
                break;

            // ===== WHM: DAFTAR RESELLER =====
            case 'sa_whm_reseller_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar reseller...');
                $resList = WhmManager::listResellers();
                if (isset($resList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar reseller.</b>\n\n<i>" . htmlspecialchars($resList['error']) . "</i>";
                } elseif (empty($resList)) {
                    $txt = "📭 <b>Tidak ada reseller yang terdaftar.</b>";
                } else {
                    $txt = "👔 <b>Daftar Reseller WHM</b> (" . count($resList) . " reseller)\n\n";
                    foreach ($resList as $i => $res) {
                        $rn = htmlspecialchars(is_array($res) ? ($res['user'] ?? $res['name'] ?? (string)$res) : (string)$res);
                        $txt .= ($i + 1) . ". <code>{$rn}</code>\n";
                    }
                    $txt .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh',      'callback_data' => 'sa_whm_reseller_list']],
                    [['text' => '🔙 Reseller',     'callback_data' => 'sa_whm_reseller']],
                ]])]);
                break;

            // ===== WHM: PROMPT JADIKAN RESELLER =====
            case 'sa_whm_make_reseller_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "👔 <b>Jadikan Reseller</b>\n\nKirimkan username akun cPanel yang ingin dijadikan reseller:\n<code>username</code>\n\n<b>Contoh:</b>\n<code>john</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_reseller']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_make_reseller', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT HAPUS RESELLER =====
            case 'sa_whm_unsetup_reseller_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🚫 <b>Hapus Hak Reseller</b>\n\nKirimkan username reseller yang ingin dihapus hak resellernya:\n<code>username</code>\n\n<b>Contoh:</b>\n<code>john</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_reseller']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_unsetup_reseller', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: PROMPT MODIFIKASI AKUN =====
            case 'sa_whm_modify_acct_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "✏️ <b>Modifikasi Akun cPanel</b>\n\nKirimkan data per baris:\n"
                    . "<code>username\nnama_plan (opsional)\nquota_mb (opsional)\nbwlimit_mb (opsional)</code>\n\n"
                    . "<b>Contoh:</b>\n<code>john\nStarter\n2048\n10240</code>\n\n"
                    . "ℹ️ <i>Kosongkan baris yang tidak ingin diubah. Gunakan 0 untuk unlimited.</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_whm_acct']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_whm_modify_acct', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== WHM: DASHBOARD (C3) =====
            case 'sa_whm_dashboard':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil data dashboard...');
                $dash = WhmManager::serverDashboard();
                if (isset($dash['error'])) {
                    $txt = "❌ <b>Gagal mengambil data.</b>\n\n<i>" . htmlspecialchars($dash['error']) . "</i>";
                } else {
                    $txt = "📊 <b>WHM Dashboard</b>\n\n"
                         . "🌐 <b>Hostname:</b> <code>" . htmlspecialchars($dash['hostname']) . "</code>\n"
                         . "📦 <b>Versi WHM:</b> <code>" . htmlspecialchars($dash['version']) . "</code>\n\n"
                         . "👥 <b>Total Akun:</b> <b>" . $dash['total'] . "</b>\n"
                         . "⛔ <b>Suspended:</b> <b>" . $dash['suspended'] . "</b>\n\n"
                         . "📈 <b>Load Average:</b>\n"
                         . "  • 1 menit:  <code>" . $dash['load1'] . "</code>\n"
                         . "  • 5 menit:  <code>" . $dash['load5'] . "</code>\n"
                         . "  • 15 menit: <code>" . $dash['load15'] . "</code>\n\n"
                         . "🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_whm_dashboard']],
                    [['text' => '🔙 Panel WHM', 'callback_data' => 'sa_whm']],
                ]])]);
                break;

            // ================================================================
            //  CPANEL: MYSQL DATABASE (submenu)
            // ================================================================

            case 'sa_cpanel_db':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "🗄️ <b>MySQL Database — cPanel</b>\n\nPilih operasi database:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbCpanelDb())]);
                break;

            // ===== CPANEL: DAFTAR DATABASE =====
            case 'sa_cpanel_db_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar database...');
                $dbList = CpanelManager::dbList();
                if (isset($dbList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar database.</b>\n\n<i>" . htmlspecialchars($dbList['error']) . "</i>";
                } elseif (empty($dbList)) {
                    $txt = "📭 <b>Tidak ada database MySQL.</b>";
                } else {
                    $txt = "🗄️ <b>Daftar Database MySQL</b> (" . count($dbList) . " database)\n\n";
                    foreach ($dbList as $i => $db) {
                        $name = htmlspecialchars(is_array($db) ? ($db['database'] ?? $db['name'] ?? '-') : (string)$db);
                        $txt .= ($i + 1) . ". <code>{$name}</code>\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_cpanel_db_list']],
                    [['text' => '🔙 MySQL', 'callback_data' => 'sa_cpanel_db']],
                ]])]);
                break;

            // ===== CPANEL: PROMPT BUAT DATABASE =====
            case 'sa_cpanel_db_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $dbPrefix = CpanelManager::getDbPrefix();
                $r = self::send($this->chatId,
                    "➕ <b>Buat Database MySQL</b>\n\nKirimkan nama database (tanpa prefix):\n<code>nama_database</code>\n\n"
                    . ($dbPrefix ? "ℹ️ Prefix <b><code>{$dbPrefix}</code></b> akan ditambahkan otomatis.\n<b>Contoh:</b> ketik <code>blog</code> → dibuat sebagai <code>{$dbPrefix}blog</code>" : '')
                    . "\n\n⚠️ <i>Hanya huruf, angka, dan underscore.</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_db']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_db_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT HAPUS DATABASE =====
            case 'sa_cpanel_db_delete_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus Database MySQL</b>\n\n⚠️ Data tidak dapat dipulihkan!\n\nKirimkan nama database yang ingin dihapus:\n<code>nama_database</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_db']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_db_delete', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT BUAT USER DATABASE =====
            case 'sa_cpanel_db_user_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $dbPrefix = CpanelManager::getDbPrefix();
                $r = self::send($this->chatId,
                    "👤 <b>Buat User Database MySQL</b>\n\nKirimkan dalam satu baris:\n<code>username password</code>\n\n"
                    . ($dbPrefix ? "ℹ️ Prefix <b><code>{$dbPrefix}</code></b> ditambahkan otomatis.\n<b>Contoh:</b> ketik <code>john secret123</code> → user dibuat sebagai <code>{$dbPrefix}john</code>" : "<b>Contoh:</b>\n<code>john secret123</code>"),
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_db']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_db_user_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT GRANT PRIVILEGES =====
            case 'sa_cpanel_db_grant_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $dbPrefix = CpanelManager::getDbPrefix();
                $r = self::send($this->chatId,
                    "🔑 <b>Grant Privileges Database</b>\n\nKirimkan dalam satu baris:\n<code>nama_database nama_user</code>\n\n"
                    . ($dbPrefix ? "ℹ️ Prefix <b><code>{$dbPrefix}</code></b> ditambahkan otomatis ke keduanya.\n<b>Contoh:</b> ketik <code>blog john</code> → grant <code>{$dbPrefix}john</code> ke <code>{$dbPrefix}blog</code>" : "<b>Contoh:</b>\n<code>mydb_prod mydb_john</code>")
                    . "\n\n<i>Privileges yang diberikan: ALL PRIVILEGES</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_db']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_db_grant', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  CPANEL: SUBDOMAIN (submenu)
            // ================================================================

            case 'sa_cpanel_subdomain':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "🌐 <b>Subdomain — cPanel</b>\n\nPilih operasi subdomain:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbCpanelSubdomain())]);
                break;

            // ===== CPANEL: DAFTAR SUBDOMAIN =====
            case 'sa_cpanel_subdomain_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar subdomain...');
                $subList = CpanelManager::subdomainList();
                if (isset($subList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar subdomain.</b>\n\n<i>" . htmlspecialchars($subList['error']) . "</i>";
                } elseif (empty($subList)) {
                    $txt = "📭 <b>Tidak ada subdomain.</b>";
                } else {
                    $txt = "🌐 <b>Daftar Subdomain</b> (" . count($subList) . ")\n\n";
                    foreach ($subList as $i => $s) {
                        $sub = htmlspecialchars(is_array($s) ? ($s['domain'] ?? $s['subdomain'] ?? '-') : (string)$s);
                        $dir = is_array($s) ? htmlspecialchars($s['dir'] ?? $s['docroot'] ?? '') : '';
                        $txt .= ($i + 1) . ". <code>{$sub}</code>" . ($dir ? "\n   📁 <code>{$dir}</code>" : '') . "\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_cpanel_subdomain_list']],
                    [['text' => '🔙 Subdomain', 'callback_data' => 'sa_cpanel_subdomain']],
                ]])]);
                break;

            // ===== CPANEL: PROMPT BUAT SUBDOMAIN =====
            case 'sa_cpanel_subdomain_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Buat Subdomain Baru</b>\n\nKirimkan dalam satu baris:\n<code>sub domain.com [/public_html/sub]</code>\n\n<b>Contoh:</b>\n<code>blog example.com</code>\n<code>api example.com /public_html/api</code>\n\n<i>Direktori bersifat opsional.</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_subdomain']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_subdomain_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT HAPUS SUBDOMAIN =====
            case 'sa_cpanel_subdomain_delete_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus Subdomain</b>\n\nKirimkan dalam satu baris:\n<code>sub domain.com</code>\n\n<b>Contoh:</b>\n<code>blog example.com</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_subdomain']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_subdomain_delete', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  CPANEL: EMAIL FORWARDER (submenu)
            // ================================================================

            case 'sa_cpanel_forwarder':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "📧 <b>Email Forwarder — cPanel</b>\n\nPilih operasi forwarder email:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbCpanelForwarder())]);
                break;

            // ===== CPANEL: DAFTAR FORWARDER =====
            case 'sa_cpanel_forwarder_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar forwarder...');
                $fwdList = CpanelManager::forwarderList();
                if (isset($fwdList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar forwarder.</b>\n\n<i>" . htmlspecialchars($fwdList['error']) . "</i>";
                } elseif (empty($fwdList)) {
                    $txt = "📭 <b>Tidak ada email forwarder.</b>";
                } else {
                    $txt = "📧 <b>Daftar Email Forwarder</b> (" . count($fwdList) . ")\n\n";
                    foreach ($fwdList as $i => $fwd) {
                        $from = htmlspecialchars(is_array($fwd) ? ($fwd['email'] ?? $fwd['address'] ?? '-') : (string)$fwd);
                        $to   = is_array($fwd) ? htmlspecialchars($fwd['forward'] ?? $fwd['fwdemail'] ?? '-') : '-';
                        $txt .= ($i + 1) . ". <code>{$from}</code> → <code>{$to}</code>\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_cpanel_forwarder_list']],
                    [['text' => '🔙 Forwarder', 'callback_data' => 'sa_cpanel_forwarder']],
                ]])]);
                break;

            // ===== CPANEL: PROMPT TAMBAH FORWARDER =====
            case 'sa_cpanel_forwarder_create_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Tambah Email Forwarder</b>\n\nKirimkan dalam satu baris:\n<code>dari@domain.com ke@gmail.com</code>\n\n<b>Contoh:</b>\n<code>info@example.com admin@gmail.com</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_forwarder']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_forwarder_create', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT HAPUS FORWARDER =====
            case 'sa_cpanel_forwarder_delete_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus Email Forwarder</b>\n\nKirimkan dalam satu baris:\n<code>dari@domain.com ke@gmail.com</code>\n\n<b>Contoh:</b>\n<code>info@example.com admin@gmail.com</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_forwarder']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_forwarder_delete', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  CPANEL: CRON JOBS (submenu)
            // ================================================================

            case 'sa_cpanel_cron':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $this->db->clearPendingInput($this->userId);
                self::edit($this->chatId, $msgId,
                    "⏰ <b>Cron Jobs — cPanel</b>\n\nPilih operasi cron job:",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode($this->kbCpanelCron())]);
                break;

            // ===== CPANEL: DAFTAR CRON =====
            case 'sa_cpanel_cron_list':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar cron...');
                $cronList = CpanelManager::cronList();
                if (isset($cronList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar cron.</b>\n\n<i>" . htmlspecialchars($cronList['error']) . "</i>";
                } elseif (empty($cronList)) {
                    $txt = "📭 <b>Tidak ada cron job.</b>";
                } else {
                    $txt = "⏰ <b>Daftar Cron Jobs</b> (" . count($cronList) . ")\n\n";
                    foreach ($cronList as $i => $cr) {
                        $min  = $cr['minute']  ?? '*';
                        $hr   = $cr['hour']    ?? '*';
                        $day  = $cr['day']     ?? '*';
                        $mon  = $cr['month']   ?? '*';
                        $wd   = $cr['weekday'] ?? '*';
                        $cmd  = htmlspecialchars($cr['command'] ?? '-');
                        $txt .= ($i + 1) . ". <code>{$min} {$hr} {$day} {$mon} {$wd}</code>\n   <code>{$cmd}</code>\n\n";
                    }
                    $txt .= "<i>Untuk hapus, klik tombol di bawah dan kirim nomor cron.</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_cpanel_cron_list']],
                    [['text' => '🔙 Cron Jobs', 'callback_data' => 'sa_cpanel_cron']],
                ]])]);
                break;

            // ===== CPANEL: PROMPT TAMBAH CRON =====
            case 'sa_cpanel_cron_add_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "➕ <b>Tambah Cron Job</b>\n\nKirimkan dalam format crontab (5 kolom waktu + command):\n<code>menit jam hari bulan hari-minggu command</code>\n\n<b>Contoh:</b>\n<code>0 * * * * /usr/bin/php /home/user/cron.php</code>\n<code>*/5 * * * * curl https://example.com/ping</code>\n\n<i>Gunakan * untuk semua nilai.</i>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_cron']]]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_cron_add', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ===== CPANEL: PROMPT HAPUS CRON =====
            case 'sa_cpanel_cron_delete_prompt':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                $r = self::send($this->chatId,
                    "🗑️ <b>Hapus Cron Job</b>\n\nLihat daftar cron terlebih dahulu, lalu kirimkan <b>nomor</b> cron yang ingin dihapus:\n<code>2</code>",
                    ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '📋 Lihat Daftar', 'callback_data' => 'sa_cpanel_cron_list']],
                        [['text' => '❌ Batal', 'callback_data' => 'sa_cpanel_cron']],
                    ]])]);
                $this->db->setPendingInput($this->userId, 0, 'sa_cpanel_cron_delete', $r['result']['message_id'] ?? 0, $msgId);
                break;

            // ================================================================
            //  CPANEL: DISK USAGE (B5)
            // ================================================================

            case 'sa_cpanel_disk':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil info disk...');
                $disk = CpanelManager::diskUsage();
                if (isset($disk['error'])) {
                    $txt = "❌ <b>Gagal mengambil info disk.</b>\n\n<i>" . htmlspecialchars($disk['error']) . "</i>";
                } elseif (empty($disk)) {
                    $txt = "💾 <b>Disk Usage</b>\n\n<i>Tidak ada data disk.</i>";
                } else {
                    $makeBar = function(float $pct): string {
                        $fill = min(10, (int)($pct / 10));
                        return str_repeat('█', $fill) . str_repeat('░', 10 - $fill);
                    };
                    $fmtMb = function(int $mb): string {
                        if ($mb >= 1024) return round($mb / 1024, 2) . ' GB';
                        return "{$mb} MB";
                    };
                    $txt = "💾 <b>Disk Usage — cPanel</b>\n\n";

                    if (isset($disk['megabytesused'])) {
                        // Data dari Quota::get_quota_info (byte-level)
                        $used  = (int)$disk['megabytesused'];
                        $limit = (int)$disk['megabyteslimit'];
                        $pct   = ($limit > 0) ? round(($used / $limit) * 100, 1) : 0;
                        $bar   = $makeBar($pct);
                        $txt  .= "🗄️ <b>Disk:</b> " . $fmtMb($used)
                               . " / " . ($limit > 0 ? $fmtMb($limit) : 'Unlimited')
                               . " ({$pct}%)\n<code>[{$bar}]</code>\n";
                        if (isset($disk['inodes_used']) && $disk['inodes_used'] > 0) {
                            $iUsed  = number_format($disk['inodes_used']);
                            $iLimit = $disk['inodes_limit'] > 0 ? number_format($disk['inodes_limit']) : 'Unlimited';
                            $txt .= "📄 <b>Inodes:</b> {$iUsed} / {$iLimit}\n";
                        }
                    } elseif (isset($disk['disk_count'])) {
                        // Data dari StatsBar::get_stats (string format)
                        $pct  = (float)($disk['disk_percent'] ?? 0);
                        $bar  = $makeBar($pct);
                        $txt .= "🗄️ <b>Disk:</b> " . htmlspecialchars($disk['disk_count'])
                              . " / " . htmlspecialchars($disk['disk_max'])
                              . " ({$pct}%)\n<code>[{$bar}]</code>\n";
                    }

                    // Extra stats (filesusage, bandwidthusage, mysqldiskusage)
                    $extra = $disk['extra'] ?? [];
                    if (!empty($extra)) {
                        $txt .= "\n";
                        $extraLabels = [
                            'filesusage'      => '📁 Files',
                            'bandwidthusage'  => '🌐 Bandwidth',
                            'mysqldiskusage'  => '🗃️ MySQL Disk',
                            'cachedmysqldiskusage' => '🗃️ MySQL Disk',
                        ];
                        foreach ($extraLabels as $key => $label) {
                            if (!isset($extra[$key])) continue;
                            $e   = $extra[$key];
                            $ep  = (float)($e['percent'] ?? 0);
                            $ebar = $makeBar($ep);
                            $txt .= "{$label}: " . htmlspecialchars($e['count'])
                                  . " / " . htmlspecialchars($e['max'])
                                  . " ({$ep}%)\n<code>[{$ebar}]</code>\n";
                        }
                    }
                    $txt .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => 'sa_cpanel_disk']],
                    [['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']],
                ]])]);
                break;

            // ================================================================
            //  CPANEL: SSL/TLS (B6)
            // ================================================================

            case 'sa_cpanel_ssl':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Mengambil daftar SSL...');
                $sslList = CpanelManager::sslList();
                if (isset($sslList['error'])) {
                    $txt = "❌ <b>Gagal mengambil daftar SSL.</b>\n\n<i>" . htmlspecialchars($sslList['error']) . "</i>";
                } elseif (empty($sslList)) {
                    $txt = "🔒 <b>SSL/TLS</b>\n\n<i>Tidak ada sertifikat SSL yang ditemukan.</i>";
                } else {
                    $txt = "🔒 <b>Daftar Sertifikat SSL</b> (" . count($sslList) . ")\n\n";
                    $now = time();
                    foreach ($sslList as $i => $cert) {
                        $domain  = htmlspecialchars($cert['domains'][0] ?? $cert['domain'] ?? '-');
                        $issuer  = htmlspecialchars($cert['issuer']['organizationName'] ?? $cert['issuer'] ?? 'Unknown');
                        $expiry  = (int)($cert['not_after'] ?? $cert['expiry_date'] ?? 0);
                        $days    = $expiry > 0 ? (int)(($expiry - $now) / 86400) : 0;
                        $status  = $days <= 0 ? '❌ Expired' : ($days < 30 ? "⚠️ {$days} hari" : "✅ {$days} hari");
                        $expDate = $expiry > 0 ? date('d/m/Y', $expiry) : '-';
                        $txt .= ($i + 1) . ". <b><code>{$domain}</code></b>\n"
                             . "   🏢 {$issuer}\n"
                             . "   📅 Expired: {$expDate} — {$status}\n\n";
                    }
                }
                self::edit($this->chatId, $msgId, $txt, ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔄 Run AutoSSL', 'callback_data' => 'sa_cpanel_ssl_autossl']],
                    [['text' => '🔄 Refresh',     'callback_data' => 'sa_cpanel_ssl']],
                    [['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']],
                ]])]);
                break;

            // ===== CPANEL: RUN AUTOSSL =====
            case 'sa_cpanel_ssl_autossl':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId, '⏳ Menjalankan AutoSSL...');
                $res = CpanelManager::sslInstallAutoSSL();
                if ($res['success'] ?? false) {
                    self::edit($this->chatId, $msgId, "✅ <b>AutoSSL berhasil dijalankan.</b>\n\nSertifikat akan diperbarui dalam beberapa menit.",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔒 Lihat SSL', 'callback_data' => 'sa_cpanel_ssl']],
                            [['text' => '🔙 Panel cPanel', 'callback_data' => 'sa_cpanel']],
                        ]])]);
                } else {
                    $err = htmlspecialchars($res['error'] ?? 'Gagal menjalankan AutoSSL.');
                    self::edit($this->chatId, $msgId, "❌ <b>Gagal menjalankan AutoSSL.</b>\n\n<i>{$err}</i>",
                        ['parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
                            [['text' => '🔙 SSL/TLS', 'callback_data' => 'sa_cpanel_ssl']],
                        ]])]);
                }
                break;

            // ================================================================
            //  SA TEMPMAIL PANEL
            // ================================================================

            case 'sa_tempmail':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                self::answerCb($cbId);
                TempMailBot::handleCommand($this->chatId, $this->userId, $this->db);
                self::del($this->chatId, $msgId);
                break;

            // ===== TUTUP PESAN (hapus pesan dengan tombol close) =====
            case 'close_msg':
                self::answerCb($cbId);
                self::del($this->chatId, $msgId);
                break;

            // ===== CLOSE SETTINGS (hapus pesan settings) =====
            case 'close_settings':
                self::answerCb($cbId);
                self::del($this->chatId, $msgId);
                break;

            // ===== CLEAR ALL: KONFIRMASI =====
            case 'clearall_confirm': {
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $targetChat = $p1 ? (int)$p1 : $this->chatId;

                // Jawab callback agar tombol tidak loading lama
                self::answerCb($cbId, '⏳ Sedang menghapus pesan...');

                // $msgId = ID panel konfirmasi = pesan terakhir di chat saat ini
                // Simpan sebelum dihapus agar bisa dijadikan batas atas pencarian
                $anchorMsgId = $msgId;

                // Hapus pesan konfirmasi
                self::del($this->chatId, $msgId);

                // Perpanjang batas waktu eksekusi PHP untuk operasi besar
                @set_time_limit(180);
                @ignore_user_abort(true);

                $attempted = 0;

                // === STRATEGI 1: Gunakan message_log yang tercatat (teknik Rose Bot) ===
                // Lebih akurat karena hanya menghapus ID yang benar-benar ada
                $loggedIds = $this->db->getLoggedMessageIds($targetChat, 4000);

                if (!empty($loggedIds)) {
                    // Tambahkan semua ID antara ID terbesar yang tercatat dan panel konfirmasi
                    // (menangkap pesan konfirmasi, pesan bot lain, dll yang belum ter-log)
                    // TANPA kirim pesan probe — agar tidak terlihat oleh member grup
                    $maxLogged = max($loggedIds);
                    if ($anchorMsgId > $maxLogged) {
                        for ($x = $maxLogged + 1; $x <= $anchorMsgId; $x++) {
                            $loggedIds[] = $x;
                        }
                    }
                    // Pastikan tidak ada duplikat dan urutkan
                    $loggedIds = array_values(array_unique($loggedIds));

                    $attempted = count($loggedIds);

                    // Hapus dalam batch 100 sekaligus (deleteMessages — jauh lebih cepat dari loop)
                    // Telegram mengembalikan true untuk batch, bukan per-pesan —
                    // pesan tidak ada / terlalu lama secara diam-diam dilewati
                    foreach (array_chunk($loggedIds, 100) as $batch) {
                        self::api('deleteMessages', ['chat_id' => $targetChat, 'message_ids' => $batch]);
                        usleep(200000); // 200ms delay — sesuai batas rate Telegram
                    }

                    // Bersihkan log setelah operasi selesai
                    $this->db->clearMessageLog($targetChat);

                } else {
                    // === STRATEGI 2: Fallback range (jika log belum ada — bot baru dipasang) ===
                    // Gunakan $anchorMsgId (panel konfirmasi) sebagai batas atas
                    // TANPA kirim pesan probe ke grup
                    $rangeLimit = 2000;
                    $ids = range(max(1, $anchorMsgId - $rangeLimit), $anchorMsgId);
                    $attempted = count($ids);

                    foreach (array_chunk($ids, 100) as $batch) {
                        self::api('deleteMessages', ['chat_id' => $targetChat, 'message_ids' => $batch]);
                        usleep(200000); // 200ms delay
                    }
                }

                // Kirim notifikasi selesai, lalu hapus otomatis setelah 4 detik
                $doneMsg = self::send($targetChat, $this->t('clearall.done', ['n' => $attempted]));
                sleep(4);
                if (!empty($doneMsg['result']['message_id'])) {
                    self::del($targetChat, $doneMsg['result']['message_id']);
                }
                break;
            }

            // ===== CLEAR ALL: BATAL =====
            case 'clearall_cancel':
                self::answerCb($cbId, $this->t('clearall.cancelled'));
                self::del($this->chatId, $msgId);
                break;

            // ===== SETTINGS: CLEARALL (dari tombol di panel settings) =====
            case 'settings_clearall': {
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $targetCid = $p1 ? (int)$p1 : $this->chatId;
                // Hitung pesan yang tercatat di log (lebih informatif)
                $logCount  = $this->db->countLoggedMessages($targetCid);
                $showCount = $logCount > 0 ? $logCount : '~1000';
                $confirmText = $this->t('clearall.confirm', ['n' => $showCount]);
                self::edit($this->chatId, $msgId, $confirmText, ['reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => $this->t('clearall.btn_yes'), 'callback_data' => "clearall_confirm:{$targetCid}:0"],
                    ['text' => $this->t('clearall.btn_no'),  'callback_data' => "settings_menu:{$targetCid}"],
                ]]])]);
                break;
            }

            // ===== SETTINGS GRUP (MAIN MENU) =====
            case 'settings_menu':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $chatIdForSettings = $p1 ? (int)$p1 : $this->chatId;
                $s = $this->db->getGroupSettings($chatIdForSettings);
                self::edit($this->chatId, $msgId, $this->txtSettings($s, $chatIdForSettings), ['reply_markup' => json_encode($this->kbSettings($s, $chatIdForSettings))]);
                break;

            // Toggle umum — reusable
            case 'toggle':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $field        = $p1;
                $chatForToggle = $p2 ? (int)$p2 : $this->chatId;
                $s     = $this->db->getGroupSettings($chatForToggle);
                $new   = ($s[$field] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($chatForToggle, $field, $new);
                $labels = [
                    'welcome_enabled'    => [$this->t('toggle.welcome_on'),      $this->t('toggle.welcome_off')],
                    'goodbye_enabled'    => [$this->t('toggle.goodbye_on'),      $this->t('toggle.goodbye_off')],
                    'antiflood_enabled'  => [$this->t('toggle.flood_on'),        $this->t('toggle.flood_off')],
                    'antilink_enabled'   => [$this->t('toggle.antilink_on'),     $this->t('toggle.antilink_off')],
                    'word_filter_enabled'=> [$this->t('toggle.wordfilter_on'),   $this->t('toggle.wordfilter_off')],
                    'captcha_enabled'    => [$this->t('toggle.captcha_on'),      $this->t('toggle.captcha_off')],
                    'anti_raid'          => [$this->t('toggle.antiraid_on'),     $this->t('toggle.antiraid_off')],
                    'sang_mata_enabled'  => [$this->t('toggle.sang_mata_on'),    $this->t('toggle.sang_mata_off')],
                ];
                $lbl = $labels[$field] ?? [$this->t('toggle.on'), $this->t('toggle.off')];
                self::answerCb($cbId, $lbl[$new ? 0 : 1]);
                $s[$field] = $new;
                self::edit($this->chatId, $msgId, $this->txtSettings($s, $chatForToggle), ['reply_markup' => json_encode($this->kbSettings($s, $chatForToggle))]);
                break;

            // ===== SUB-MENU WELCOME =====
            case 'settings_welcome':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtWelcomeSettings($s), ['reply_markup' => json_encode($this->kbWelcomeSettings($s, $cid, $msgId))]);
                break;

            case 'set_welcome_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $r   = self::send($this->chatId,
                    $this->t('input.set_welcome'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_welcome', $promptId, $msgId);
                break;

            case 'set_welcome_btn_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $r   = self::send($this->chatId,
                    $this->t('input.set_welcome_btn'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_welcome_button', $promptId, $msgId);
                break;

            case 'clear_welcome_buttons':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $this->db->updateGroupSetting($cid, 'welcome_buttons', '[]');
                self::answerCb($cbId, $this->t('toggle.btns_cleared'));
                $s = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtWelcomeSettings($s), ['reply_markup' => json_encode($this->kbWelcomeSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_welcome':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['welcome_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'welcome_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.welcome_on') : $this->t('toggle.welcome_off'));
                $s['welcome_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtWelcomeSettings($s), ['reply_markup' => json_encode($this->kbWelcomeSettings($s, $cid, $msgId))]);
                break;

            // ===== SUB-MENU GOODBYE =====
            case 'settings_goodbye':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtGoodbyeSettings($s), ['reply_markup' => json_encode($this->kbGoodbyeSettings($s, $cid, $msgId))]);
                break;

            case 'set_goodbye_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $r   = self::send($this->chatId,
                    $this->t('input.set_goodbye'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_goodbye', $promptId, $msgId);
                break;

            case 'toggle_goodbye':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['goodbye_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'goodbye_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.goodbye_on') : $this->t('toggle.goodbye_off'));
                $s['goodbye_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtGoodbyeSettings($s), ['reply_markup' => json_encode($this->kbGoodbyeSettings($s, $cid, $msgId))]);
                break;

            // ===== SUB-MENU RULES =====
            case 'settings_rules':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                $rulesContent = htmlspecialchars($s['rules']) ?: $this->t('settings.rules_not_set');
                $txt = $this->t('settings.rules_display', ['content' => $rulesContent]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbRulesSettings($s, $cid, $msgId))]);
                break;

            case 'set_rules_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $r   = self::send($this->chatId, $this->t('input.set_rules'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_rules', $promptId, $msgId);
                break;

            case 'clear_rules':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $this->db->updateGroupSetting($cid, 'rules', '');
                self::answerCb($cbId, $this->t('toggle.rules_cleared'));
                $s = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->t('settings.rules_display', ['content' => $this->t('settings.rules_not_set')]), ['reply_markup' => json_encode($this->kbRulesSettings($s, $cid, $msgId))]);
                break;

            // ===== SUB-MENU CAPTCHA =====
            case 'settings_captcha':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtCaptchaSettings($s), ['reply_markup' => json_encode($this->kbCaptchaSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_captcha':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['captcha_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'captcha_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.captcha_on') : $this->t('toggle.captcha_off'));
                $s['captcha_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtCaptchaSettings($s), ['reply_markup' => json_encode($this->kbCaptchaSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_captcha_type':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid     = (int)$p1;
                $s       = $this->db->getGroupSettings($cid);
                $current = $s['captcha_type'] ?? 'math';
                $newType = ($current === 'math') ? 'hcaptcha' : 'math';

                // Cek apakah hCaptcha sudah dikonfigurasi
                if ($newType === 'hcaptcha' && (HCAPTCHA_SITEKEY === '' || HCAPTCHA_SECRET === '')) {
                    self::answerCb($cbId, $this->t('captcha.hcaptcha_not_configured'), true);
                    break;
                }

                $this->db->updateGroupSetting($cid, 'captcha_type', $newType);
                self::answerCb($cbId, $this->t('captcha.type_switched_' . $newType));
                $s['captcha_type'] = $newType;
                self::edit($this->chatId, $msgId, $this->txtCaptchaSettings($s), ['reply_markup' => json_encode($this->kbCaptchaSettings($s, $cid, $msgId))]);
                break;

            case 'set_captcha_timeout_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $r   = self::send($this->chatId,
                    $this->t('input.set_captcha_timeout'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_captcha_timeout', $promptId, $msgId);
                break;

            // ===== SUB-MENU ANTI-LINK =====
            case 'settings_antilink':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtAntilinkSettings($s), ['reply_markup' => json_encode($this->kbAntilinkSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_antilink':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['antilink_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'antilink_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.antilink_on') : $this->t('toggle.antilink_off'));
                $s['antilink_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtAntilinkSettings($s), ['reply_markup' => json_encode($this->kbAntilinkSettings($s, $cid, $msgId))]);
                break;

            case 'set_antilink_wl_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $wl  = json_decode($s['antilink_whitelist'] ?? '[]', true) ?: [];
                $r   = self::send($this->chatId,
                    $this->t('input.set_antilink_wl'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_antilink_whitelist', $promptId, $msgId);
                break;

            case 'clear_antilink_wl':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $this->db->updateGroupSetting($cid, 'antilink_whitelist', '[]');
                self::answerCb($cbId, $this->t('toggle.wl_cleared'));
                $s = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtAntilinkSettings($s), ['reply_markup' => json_encode($this->kbAntilinkSettings($s, $cid, $msgId))]);
                break;

            // ===== SUB-MENU WORD FILTER =====
            case 'settings_wordfilter':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtWordFilterSettings($s), ['reply_markup' => json_encode($this->kbWordFilterSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_wordfilter':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['word_filter_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'word_filter_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.wordfilter_on') : $this->t('toggle.wordfilter_off'));
                $s['word_filter_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtWordFilterSettings($s), ['reply_markup' => json_encode($this->kbWordFilterSettings($s, $cid, $msgId))]);
                break;

            case 'set_banword_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $r   = self::send($this->chatId,
                    $this->t('input.set_banword'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_banword', $promptId, $msgId);
                break;

            case 'clear_banwords':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $this->db->updateGroupSetting($cid, 'banned_words', '[]');
                $this->db->updateGroupSetting($cid, 'word_filter_enabled', 0);
                self::answerCb($cbId, $this->t('toggle.banwords_cleared'));
                $s = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtWordFilterSettings($s), ['reply_markup' => json_encode($this->kbWordFilterSettings($s, $cid, $msgId))]);
                break;

            // ===== SUB-MENU LOG CHANNEL =====
            case 'settings_logchannel':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                $txt = $this->t('settings.logchannel_text', ['channel' => htmlspecialchars($s['log_channel'] ?: '')]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbLogChannelSettings($cid, $msgId))]);
                break;

            case 'set_logchannel_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $r   = self::send($this->chatId,
                    $this->t('input.set_logchannel'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_logchannel', $promptId, $msgId);
                break;

            case 'clear_logchannel':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $this->db->updateGroupSetting($cid, 'log_channel', '');
                self::answerCb($cbId, $this->t('toggle.logchannel_cleared'));
                self::edit($this->chatId, $msgId, $this->t('settings.logchannel_cleared'), ['reply_markup' => json_encode($this->kbLogChannelSettings($cid, $msgId))]);
                break;

            // ===== LOCKS =====
            case 'settings_locks':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtLocks($s), ['reply_markup' => json_encode($this->kbLocks($s, $cid, $msgId))]);
                break;

            case 'toggle_lock':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $field = $p1;
                $cid   = (int)$p2;
                $s     = $this->db->getGroupSettings($cid);
                $new   = ($s[$field] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, $field, $new);
                $map   = ['lock_sticker' => 'Sticker', 'lock_gif' => 'GIF', 'lock_media' => 'Media', 'lock_voice' => 'Voice', 'lock_game' => 'Game', 'lock_poll' => 'Poll', 'anti_forward' => 'Forward'];
                $name  = $map[$field] ?? $field;
                self::answerCb($cbId, $new ? $this->t('toggle.lock_locked', ['name' => $name]) : $this->t('toggle.lock_unlocked', ['name' => $name]));
                $s[$field] = $new;
                self::edit($this->chatId, $msgId, $this->txtLocks($s), ['reply_markup' => json_encode($this->kbLocks($s, $cid, $msgId))]);
                break;

            // ===== FLOOD SETTINGS =====
            case 'settings_flood':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtFloodSettings($s), ['reply_markup' => json_encode($this->kbFlood($s, $cid, $msgId))]);
                break;

            case 'toggle_flood':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['antiflood_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'antiflood_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.flood_on') : $this->t('toggle.flood_off'));
                $s['antiflood_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtFloodSettings($s), ['reply_markup' => json_encode($this->kbFlood($s, $cid, $msgId))]);
                break;

            case 'flood_limit':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $limits = [3, 5, 8, 10, 15, 20];
                $cur    = array_search((int)$s['antiflood_limit'], $limits);
                $next   = $limits[(($cur === false ? 1 : $cur) + 1) % count($limits)];
                $this->db->updateGroupSetting($cid, 'antiflood_limit', $next);
                self::answerCb($cbId, $this->t('toggle.flood_limit', ['n' => $next]));
                $s['antiflood_limit'] = $next;
                self::edit($this->chatId, $msgId, $this->txtFloodSettings($s), ['reply_markup' => json_encode($this->kbFlood($s, $cid, $msgId))]);
                break;

            case 'flood_action':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid     = (int)$p1;
                $s       = $this->db->getGroupSettings($cid);
                $actions = ['mute', 'kick', 'ban'];
                $cur     = array_search($s['antiflood_action'], $actions);
                $next    = $actions[(($cur === false ? 0 : $cur) + 1) % count($actions)];
                $this->db->updateGroupSetting($cid, 'antiflood_action', $next);
                self::answerCb($cbId, $this->t('toggle.flood_action', ['action' => $next]));
                $s['antiflood_action'] = $next;
                self::edit($this->chatId, $msgId, $this->txtFloodSettings($s), ['reply_markup' => json_encode($this->kbFlood($s, $cid, $msgId))]);
                break;

            case 'flood_duration_prompt':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $r   = self::send($this->chatId,
                    $this->t('input.set_flood_duration'),
                    ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => $this->t('prompt.cancel_btn'), 'callback_data' => 'cancel_input:' . $cid]]]])]);
                $promptId = $r['result']['message_id'] ?? 0;
                $this->db->setPendingInput($this->userId, $cid, 'set_flood_duration', $promptId, $msgId);
                break;

            // ===== WARN SETTINGS =====
            case 'settings_warn':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtWarnSettings($s), ['reply_markup' => json_encode($this->kbWarn($s, $cid, $msgId))]);
                break;

            case 'warn_limit':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $limits = [2, 3, 5, 7, 10];
                $cur    = array_search((int)$s['warn_limit'], $limits);
                $next   = $limits[(($cur === false ? 1 : $cur) + 1) % count($limits)];
                $this->db->updateGroupSetting($cid, 'warn_limit', $next);
                self::answerCb($cbId, $this->t('toggle.warn_limit', ['n' => $next]));
                $s['warn_limit'] = $next;
                self::edit($this->chatId, $msgId, $this->txtWarnSettings($s), ['reply_markup' => json_encode($this->kbWarn($s, $cid, $msgId))]);
                break;

            case 'warn_action':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid     = (int)$p1;
                $s       = $this->db->getGroupSettings($cid);
                $actions = ['kick', 'ban', 'mute'];
                $cur     = array_search($s['warn_action'], $actions);
                $next    = $actions[(($cur === false ? 0 : $cur) + 1) % count($actions)];
                $this->db->updateGroupSetting($cid, 'warn_action', $next);
                self::answerCb($cbId, $this->t('toggle.warn_action', ['action' => $next]));
                $s['warn_action'] = $next;
                self::edit($this->chatId, $msgId, $this->txtWarnSettings($s), ['reply_markup' => json_encode($this->kbWarn($s, $cid, $msgId))]);
                break;

            // ===== POIN SANKSI =====
            case 'settings_violation':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtViolationSettings($s), ['reply_markup' => json_encode($this->kbViolationSettings($s, $cid))]);
                break;

            case 'toggle_violation':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['violation_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'violation_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.violation_on') : $this->t('toggle.violation_off'));
                $s['violation_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtViolationSettings($s), ['reply_markup' => json_encode($this->kbViolationSettings($s, $cid))]);
                break;

            case 'violation_limit':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $limits = [3, 5, 7, 10, 15, 20];
                $cur    = array_search((int)$s['violation_limit'], $limits);
                $next   = $limits[(($cur === false ? 1 : $cur) + 1) % count($limits)];
                $this->db->updateGroupSetting($cid, 'violation_limit', $next);
                self::answerCb($cbId, $this->t('toggle.violation_limit', ['n' => $next]));
                $s['violation_limit'] = $next;
                self::edit($this->chatId, $msgId, $this->txtViolationSettings($s), ['reply_markup' => json_encode($this->kbViolationSettings($s, $cid))]);
                break;

            case 'violation_action':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid     = (int)$p1;
                $s       = $this->db->getGroupSettings($cid);
                $actions = ['kick', 'ban', 'mute'];
                $cur     = array_search($s['violation_action'], $actions);
                $next    = $actions[(($cur === false ? 0 : $cur) + 1) % count($actions)];
                $this->db->updateGroupSetting($cid, 'violation_action', $next);
                self::answerCb($cbId, $this->t('toggle.violation_action', ['action' => $next]));
                $s['violation_action'] = $next;
                self::edit($this->chatId, $msgId, $this->txtViolationSettings($s), ['reply_markup' => json_encode($this->kbViolationSettings($s, $cid))]);
                break;

            // ===== VOTE KICK =====
            case 'vk_yes':
            case 'vk_no': {
                $sessionId = (int)$p1;
                $voteVal   = ($act === 'vk_yes') ? 'yes' : 'no';
                $session   = $this->db->getVotekickSession($sessionId);

                if (!$session || $session['status'] !== 'active') {
                    self::answerCb($cbId, $this->t('vk.inactive'), true);
                    break;
                }
                if (time() > (int)$session['expires_at']) {
                    $this->db->updateVotekickStatus($sessionId, 'expired');
                    self::answerCb($cbId, $this->t('vk.expired'), true);
                    break;
                }
                // Cegah voter kick diri sendiri
                if ($this->userId === (int)$session['target_id']) {
                    self::answerCb($cbId, $this->t('vk.self_vote'), true);
                    break;
                }
                $recorded = $this->db->addVotekickVote($sessionId, $this->userId, $voteVal);
                if (!$recorded) {
                    self::answerCb($cbId, $this->t('vk.already_voted'), true);
                    break;
                }
                // Ambil data sesi terbaru setelah update count
                $session = $this->db->getVotekickSession($sessionId);
                $yes     = (int)$session['yes_count'];
                $no      = (int)$session['no_count'];
                $thresh  = (int)$session['threshold'];

                if ($voteVal === 'yes') {
                    self::answerCb($cbId, $this->t('vk.vote_yes', ['yes' => $yes, 'thresh' => $thresh]));
                } else {
                    self::answerCb($cbId, $this->t('vk.vote_no'));
                }

                if ($yes >= $thresh) {
                    // Eksekusi kick
                    $this->db->updateVotekickStatus($sessionId, 'executed');
                    $tid  = (int)$session['target_id'];
                    $tnam = htmlspecialchars($session['target_name']);
                    self::kick($session['chat_id'], $tid);
                    $finalTxt = $this->t('vk.executed', ['target' => $tnam, 'yes' => $yes, 'no' => $no, 'thresh' => $thresh]);
                    self::edit($session['chat_id'], (int)$session['message_id'], $finalTxt);
                } else {
                    $durLeft = max(0, (int)$session['expires_at'] - time());
                    $minLeft = ceil($durLeft / 60);
                    self::edit(
                        $session['chat_id'],
                        (int)$session['message_id'],
                        $this->buildVotekickMsg($session, $minLeft),
                        ['reply_markup' => json_encode($this->buildVotekickKb($session))]
                    );
                }
                break;
            }

            case 'vk_cancel': {
                $sessionId = (int)$p1;
                $session   = $this->db->getVotekickSession($sessionId);
                if (!$session || $session['status'] !== 'active') {
                    self::answerCb($cbId, $this->t('vk.cancel_inactive'), true);
                    break;
                }
                if ((int)$session['initiator_id'] !== $this->userId && !$isAdm && !$isSa) {
                    self::answerCb($cbId, $this->t('vk.cancel_no_perm'), true);
                    break;
                }
                $this->db->updateVotekickStatus($sessionId, 'cancelled');
                self::answerCb($cbId, $this->t('vk.cancel_done'));
                $tnam = htmlspecialchars($session['target_name']);
                self::edit(
                    $session['chat_id'],
                    (int)$session['message_id'],
                    $this->t('vk.cancelled_full', ['target' => $tnam, 'mention' => $this->userMention])
                );
                break;
            }

            case 'settings_votekick':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'toggle_votekick':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['votekick_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'votekick_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.votekick_on') : $this->t('toggle.votekick_off'));
                $s['votekick_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'votekick_threshold':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $opts   = [3, 5, 7, 10, 15, 20];
                $cur    = array_search((int)($s['votekick_threshold'] ?? 5), $opts);
                $next   = $opts[(($cur === false ? 1 : $cur) + 1) % count($opts)];
                $this->db->updateGroupSetting($cid, 'votekick_threshold', $next);
                self::answerCb($cbId, $this->t('vk.threshold_set', ['n' => $next]));
                $s['votekick_threshold'] = $next;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'votekick_duration':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $opts   = [1, 2, 3, 5, 10, 15, 30];
                $cur    = array_search((int)($s['votekick_duration'] ?? 5), $opts);
                $next   = $opts[(($cur === false ? 2 : $cur) + 1) % count($opts)];
                $this->db->updateGroupSetting($cid, 'votekick_duration', $next);
                self::answerCb($cbId, $this->t('vk.duration_set', ['n' => $next]));
                $s['votekick_duration'] = $next;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'votekick_mode':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid  = (int)$p1;
                $s    = $this->db->getGroupSettings($cid);
                $new  = (int)($s['votekick_mode'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'votekick_mode', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.vk_mode_pct') : $this->t('toggle.vk_mode_abs'));
                $s['votekick_mode'] = $new;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'votekick_percent':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid  = (int)$p1;
                $s    = $this->db->getGroupSettings($cid);
                $opts = [5, 10, 15, 20, 25, 30];
                $cur  = array_search((int)($s['votekick_percent'] ?? 10), $opts);
                $next = $opts[(($cur === false ? 1 : $cur) + 1) % count($opts)];
                $this->db->updateGroupSetting($cid, 'votekick_percent', $next);
                self::answerCb($cbId, $this->t('vk.percent_set', ['n' => $next]));
                $s['votekick_percent'] = $next;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'toggle_vk_admin_only':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid  = (int)$p1;
                $s    = $this->db->getGroupSettings($cid);
                $new  = (int)($s['votekick_admin_only'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'votekick_admin_only', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.vk_admin_only_on') : $this->t('toggle.vk_admin_only_off'));
                $s['votekick_admin_only'] = $new;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            case 'votekick_cooldown':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid  = (int)$p1;
                $s    = $this->db->getGroupSettings($cid);
                $opts = [0, 5, 10, 15, 30, 60];
                $cur  = array_search((int)($s['votekick_cooldown'] ?? 0), $opts);
                $next = $opts[(($cur === false ? 0 : $cur) + 1) % count($opts)];
                $this->db->updateGroupSetting($cid, 'votekick_cooldown', $next);
                if ($next > 0) {
                    self::answerCb($cbId, $this->t('toggle.vk_cooldown_set', ['n' => $next]));
                } else {
                    self::answerCb($cbId, $this->t('toggle.vk_cooldown_off'));
                }
                $s['votekick_cooldown'] = $next;
                self::edit($this->chatId, $msgId, $this->txtVotekickSettings($s), ['reply_markup' => json_encode($this->kbVotekickSettings($s, $cid))]);
                break;

            // ===== AI =====
            case 'settings_ai':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                self::edit($this->chatId, $msgId, $this->txtAiSettings($s), ['reply_markup' => json_encode($this->kbAiSettings($s, $cid))]);
                break;

            case 'toggle_ai':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['ai_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'ai_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.ai_on') : $this->t('toggle.ai_off'));
                $s['ai_enabled'] = $new;
                self::edit($this->chatId, $msgId, $this->txtAiSettings($s), ['reply_markup' => json_encode($this->kbAiSettings($s, $cid))]);
                break;

            // ===== ANTI-RAID =====
            case 'settings_antiraid':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                self::answerCb($cbId);
                $cid = $p1 ? (int)$p1 : $this->chatId;
                $s   = $this->db->getGroupSettings($cid);
                $status = $s['anti_raid'] ? $this->t('antiraid.status_on') : $this->t('antiraid.status_off');
                $txt = $this->t('antiraid.full_text', ['status' => $status, 'limit' => $s['raid_limit']]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbAntiraidSettings($s, $cid, $msgId))]);
                break;

            case 'toggle_antiraid':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['anti_raid'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'anti_raid', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.antiraid_on') : $this->t('toggle.antiraid_off'));
                $s['anti_raid'] = $new;
                $status = $s['anti_raid'] ? $this->t('antiraid.status_on') : $this->t('antiraid.status_off');
                $txt = $this->t('antiraid.short_text', ['status' => $status, 'limit' => $s['raid_limit']]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbAntiraidSettings($s, $cid, $msgId))]);
                break;

            case 'raid_limit':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid    = (int)$p1;
                $s      = $this->db->getGroupSettings($cid);
                $limits = [5, 10, 15, 20, 30, 50];
                $cur    = array_search((int)$s['raid_limit'], $limits);
                $next   = $limits[(($cur === false ? 1 : $cur) + 1) % count($limits)];
                $this->db->updateGroupSetting($cid, 'raid_limit', $next);
                self::answerCb($cbId, $this->t('toggle.raid_limit', ['n' => $next]));
                $s['raid_limit'] = $next;
                $status = $s['anti_raid'] ? $this->t('antiraid.status_on') : $this->t('antiraid.status_off');
                $txt = $this->t('antiraid.short_text', ['status' => $status, 'limit' => $s['raid_limit']]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbAntiraidSettings($s, $cid, $msgId))]);
                break;

            // ===== TOGGLE REQUIRE VERIFICATION =====
            case 'toggle_require_verification':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['require_verification'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'require_verification', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.require_verif_on') : $this->t('toggle.require_verif_off'));
                $s['require_verification'] = $new;
                $txt = $this->t('settings.rules_display', ['content' => ($s['rules'] ?: $this->t('settings.rules_not_set'))]);
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbRulesSettings($s, $cid))]);
                break;

            // ===== TOGGLE RULES BTN =====
            case 'toggle_rules_btn':
                if (!$isAdm && !$isSa) { self::answerCb($cbId, $this->t('error.admin_only'), true); break; }
                $cid = (int)$p1;
                $s   = $this->db->getGroupSettings($cid);
                $new = ($s['rules_btn_enabled'] ?? 0) ? 0 : 1;
                $this->db->updateGroupSetting($cid, 'rules_btn_enabled', $new);
                self::answerCb($cbId, $new ? $this->t('toggle.rules_btn_on') : $this->t('toggle.rules_btn_off'));
                $s['rules_btn_enabled'] = $new;
                $txt = $this->t('rules.title') . "\n\n" . (htmlspecialchars($s['rules']) ?: '<i>' . $this->t('settings.not_set') . '</i>');
                self::edit($this->chatId, $msgId, $txt, ['reply_markup' => json_encode($this->kbRulesSettings($s, $cid))]);
                break;

            // ===== CANCEL INPUT =====
            case 'cancel_input':
                self::answerCb($cbId, $this->t('toggle.cancelled'));
                $this->db->clearPendingInput($this->userId);
                self::del($this->chatId, $msgId);
                break;

            // ===== CAPTCHA VERIFY =====
            case 'captcha_verify':
                $answer      = (int)$p1;
                $isPrivateCb = ($this->chatId === $this->userId);

                // Jika callback dari DM → cari berdasarkan user_id saja (answer>0 = math captcha)
                if ($isPrivateCb) {
                    $pending = $this->db->fetchOne(
                        "SELECT * FROM captcha_pending WHERE user_id=? AND answer > 0 LIMIT 1",
                        [$this->userId]
                    );
                } else {
                    $pending = $this->db->fetchOne(
                        "SELECT * FROM captcha_pending WHERE chat_id=? AND user_id=?",
                        [$this->chatId, $this->userId]
                    );
                }

                if (!$pending) { self::answerCb($cbId, $this->t('captcha.invalid'), true); break; }

                // Gunakan chat_id dari DB sebagai ID grup yang benar
                $groupChatId = (int)$pending['chat_id'];

                if (time() > (int)$pending['expires_at']) {
                    self::answerCb($cbId, $this->t('captcha.expired_kick'), true);
                    self::kick($groupChatId, $this->userId);
                    // Hapus pesan notifikasi di grup
                    if ($pending['message_id']) self::del($groupChatId, (int)$pending['message_id']);
                    // Hapus pesan captcha di DM jika dari DM
                    if ($isPrivateCb) self::del($this->chatId, $msgId);
                    $this->db->execute("DELETE FROM captcha_pending WHERE chat_id=? AND user_id=?", [$groupChatId, $this->userId]);
                    break;
                }

                if ($answer === (int)$pending['answer']) {
                    // Hapus pesan captcha (dari DM atau grup)
                    self::del($this->chatId, $msgId);
                    // Jika dari DM, hapus juga notifikasi di grup
                    if ($isPrivateCb && $pending['message_id']) {
                        self::del($groupChatId, (int)$pending['message_id']);
                    }
                    $this->db->execute("DELETE FROM captcha_pending WHERE chat_id=? AND user_id=?", [$groupChatId, $this->userId]);
                    $settings   = $this->db->getGroupSettings($groupChatId);
                    $rules      = trim($settings['rules'] ?? '');
                    $needsAgree = !empty($rules)
                               || !empty($settings['rules_btn_enabled'])
                               || !empty($settings['require_verification']);

                    if ($needsAgree) {
                        // Captcha benar → kirim tombol setuju via DM user
                        self::answerCb($cbId, $this->t('captcha.correct_rules'), true);
                        $fn        = htmlspecialchars($cb['from']['first_name'] ?? 'Pengguna');
                        $rulesText = !empty($rules)
                            ? $this->t('captcha.rules_text', ['name' => $fn, 'rules' => $rules])
                            : $this->t('rules.agree_generic_prompt', ['name' => $fn]);
                        $kb = ['inline_keyboard' => [[
                            ['text' => $this->t('captcha.rules_agree_btn'), 'callback_data' => "rules_agree:{$this->userId}:{$groupChatId}"],
                        ]]];
                        // Kirim ke DM user
                        $dmRes = self::api('sendMessage', [
                            'chat_id'      => $this->userId,
                            'text'         => $rulesText,
                            'parse_mode'   => 'HTML',
                            'reply_markup' => json_encode($kb),
                        ]);
                        if ($dmRes['ok'] ?? false) {
                            $mention = "<a href=\"tg://user?id={$this->userId}\">$fn</a>";
                            self::send($groupChatId, $this->t('rules.dm_sent_notif', ['mention' => $mention]));
                        } else {
                            // Fallback ke grup jika DM gagal
                            self::send($groupChatId, $rulesText, ['reply_markup' => json_encode($kb)]);
                        }
                    } else {
                        // Tidak ada peraturan → langsung unmute dan kirim welcome di grup
                        self::answerCb($cbId, $this->t('captcha.correct'), true);
                        self::unmute($groupChatId, $this->userId);
                        if ($settings['welcome_enabled']) {
                            $userInfo  = $cb['from'];
                            if ($isPrivateCb) {
                                $chatApi   = self::api('getChat', ['chat_id' => $groupChatId]);
                                $groupChat = $chatApi['result'] ?? ['id' => $groupChatId];
                            } else {
                                $groupChat = $cb['message']['chat'] ?? ['id' => $groupChatId];
                            }
                            $wtext     = $this->fmtWelcome($settings['welcome_message'], $userInfo, $groupChat);
                            $buttons   = json_decode($settings['welcome_buttons'] ?? '[]', true) ?: [];
                            $kb = null;
                            if (!empty($buttons)) {
                                $rows = array_chunk(array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons), 2);
                                $kb   = json_encode(['inline_keyboard' => $rows]);
                            }
                            self::send($groupChatId, $wtext, array_filter(['reply_markup' => $kb]));
                        }
                    }
                } else {
                    self::answerCb($cbId, $this->t('captcha.wrong'), true);
                }
                break;

            // ===== SETUJU PERATURAN =====
            case 'rules_agree':
                $targetUserId = (int)$p1;
                // groupChatId di-encode di $p2 (format baru) — fallback ke $this->chatId jika lama
                $groupChatId  = $p2 ? (int)$p2 : $this->chatId;

                // Hanya user yang bersangkutan boleh menekan tombol ini
                if ($this->userId !== $targetUserId) {
                    self::answerCb($cbId, $this->t('rules.not_for_you'), true);
                    break;
                }

                $settings = $this->db->getGroupSettings($groupChatId);

                // ── Cek apakah grup mengharuskan verifikasi kontak+lokasi ──
                if (!empty($settings['require_verification'])) {
                    // Sudah pernah verifikasi sebelumnya → langsung unmute
                    $existing = $this->db->getVerification($groupChatId, $targetUserId);
                    if ($existing) {
                        self::answerCb($cbId, $this->t('rules.verify_already_done'), true);
                        self::api('restrictChatMember', [
                            'chat_id'     => $groupChatId,
                            'user_id'     => $targetUserId,
                            'permissions' => [
                                'can_send_messages'         => true,
                                'can_send_audios'           => true,
                                'can_send_documents'        => true,
                                'can_send_photos'           => true,
                                'can_send_videos'           => true,
                                'can_send_video_notes'      => true,
                                'can_send_voice_notes'      => true,
                                'can_send_polls'            => true,
                                'can_send_other_messages'   => true,
                                'can_add_web_page_previews' => true,
                                'can_invite_users'          => true,
                            ],
                        ]);
                        self::del($this->chatId, $msgId); // hapus pesan DM atau grup
                        break;
                    }

                    // Belum verifikasi → kirim DM minta kontak
                    $groupTitle = htmlspecialchars($settings['title'] ?? 'grup');
                    $dmText     = str_replace('{group}', $groupTitle, $this->t('rules.verify_contact_prompt'));
                    $contactKb  = json_encode([
                        'keyboard'          => [[['text' => $this->t('rules.verify_btn_contact'), 'request_contact' => true]]],
                        'resize_keyboard'   => true,
                        'one_time_keyboard' => true,
                    ]);
                    $dmResult = self::api('sendMessage', [
                        'chat_id'      => $targetUserId,
                        'text'         => $dmText,
                        'parse_mode'   => 'HTML',
                        'reply_markup' => $contactKb,
                    ]);

                    if (!($dmResult['ok'] ?? false)) {
                        // User belum mulai bot — beri tahu di popup
                        self::answerCb($cbId, $this->t('rules.verify_dm_failed'), true);
                        break;
                    }

                    // Hapus pesan tombol setuju dari DM/grup
                    self::del($this->chatId, $msgId);

                    // Simpan pending: tunggu kiriman kontak (rules_msg_id=0 krn sudah dihapus)
                    $this->db->setPendingInput($targetUserId, 0, 'verify_contact', 0, 0, [
                        'group_chat_id' => $groupChatId,
                        'rules_msg_id'  => 0,
                    ]);

                    self::answerCb($cbId, $this->t('rules.verify_required_alert'), true);
                    break;
                }

                // ── Tidak perlu verifikasi — alur normal (langsung unmute) ──
                self::answerCb($cbId, $this->t('rules.agreed_ok'), true);

                self::api('restrictChatMember', [
                    'chat_id'     => $groupChatId,
                    'user_id'     => $targetUserId,
                    'permissions' => [
                        'can_send_messages'         => true,
                        'can_send_audios'           => true,
                        'can_send_documents'        => true,
                        'can_send_photos'           => true,
                        'can_send_videos'           => true,
                        'can_send_video_notes'      => true,
                        'can_send_voice_notes'      => true,
                        'can_send_polls'            => true,
                        'can_send_other_messages'   => true,
                        'can_add_web_page_previews' => true,
                        'can_invite_users'          => true,
                    ],
                ]);

                // Hapus pesan tombol (DM atau grup)
                self::del($this->chatId, $msgId);

                if ($settings['welcome_enabled']) {
                    $userInfo = $cb['from'];
                    // Bangun info grup manual karena callback bisa berasal dari DM
                    $grpChat  = ['id' => $groupChatId, 'title' => ($settings['title'] ?? ''), 'type' => 'supergroup'];
                    $wtext    = $this->fmtWelcome($settings['welcome_message'], $userInfo, $grpChat);
                    $buttons  = json_decode($settings['welcome_buttons'] ?? '[]', true) ?: [];
                    $kb       = null;
                    if (!empty($buttons)) {
                        $rows = array_chunk(array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons), 2);
                        $kb   = json_encode(['inline_keyboard' => $rows]);
                    }
                    self::send($groupChatId, $wtext, array_filter(['reply_markup' => $kb]));
                }
                break;

            // ================================================================
            //  TEMPMAIL CALLBACK ROUTING (tm:action[:arg])
            // ================================================================

            case 'tm':
                if (!$isSa) { self::answerCb($cbId, $this->t('sa.access_denied_alert'), true); break; }
                TempMailBot::handleCallback($data, $this->chatId, $this->userId, $msgId, $cbId, $this->db);
                break;

            default:
                self::answerCb($cbId);
                break;
        }
    }

    // ================================================================
    //  CRYPTO PRICE HELPER
    // ================================================================

    /**
     * Ambil harga crypto dari CoinGecko API (gratis, tanpa API key).
     * $from  : simbol/nama koin (btc, eth, usdt, ...)
     * $to    : mata uang tujuan (usd, idr, eur, ...) — default: usd
     * Return : ['price' => float, 'change_24h' => float|null, 'symbol' => string, 'vs' => string]
     *          atau ['error' => string]
     */
    private function getCryptoPrice(string $from, string $to = 'usd'): array {
        // Mapping simbol populer → CoinGecko ID
        $coinMap = [
            'btc'   => 'bitcoin',
            'eth'   => 'ethereum',
            'bnb'   => 'binancecoin',
            'usdt'  => 'tether',
            'usdc'  => 'usd-coin',
            'xrp'   => 'ripple',
            'sol'   => 'solana',
            'ada'   => 'cardano',
            'doge'  => 'dogecoin',
            'dot'   => 'polkadot',
            'matic' => 'matic-network',
            'ltc'   => 'litecoin',
            'trx'   => 'tron',
            'shib'  => 'shiba-inu',
            'avax'  => 'avalanche-2',
            'link'  => 'chainlink',
            'atom'  => 'cosmos',
            'uni'   => 'uniswap',
            'ton'   => 'the-open-network',
            'near'  => 'near',
            'ftm'   => 'fantom',
            'pepe'  => 'pepe',
            'wbt'   => 'whitebit',
            'idr'   => '',
        ];

        $fromLower = strtolower(trim($from));
        $toLower   = strtolower(trim($to));

        // Resolve coin ID
        $coinId = $coinMap[$fromLower] ?? $fromLower;
        if (empty($coinId)) {
            return ['error' => "Koin <b>" . htmlspecialchars(strtoupper($fromLower)) . "</b> tidak dikenali."];
        }

        // Validate vs_currency (hanya huruf dan tanda hubung)
        if (!preg_match('/^[a-z]{2,10}$/', $toLower)) {
            return ['error' => "Mata uang tujuan tidak valid: <b>" . htmlspecialchars(strtoupper($toLower)) . "</b>"];
        }

        $url = "https://api.coingecko.com/api/v3/simple/price"
             . "?ids=" . urlencode($coinId)
             . "&vs_currencies=" . urlencode($toLower)
             . "&include_24hr_change=true";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'TelegramBot/3.1',
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            return ['error' => "Gagal menghubungi server harga. Coba lagi nanti."];
        }

        $data = json_decode($raw, true);
        if (empty($data[$coinId][$toLower])) {
            // Coba cari koin yang cocok dengan nama parsial lewat search endpoint
            return ['error' => "Pasangan <b>" . htmlspecialchars(strtoupper($fromLower)) . "/" . htmlspecialchars(strtoupper($toLower)) . "</b> tidak ditemukan.\nGunakan simbol yang benar, misal: <code>/price btc idr</code>"];
        }

        $price  = (float)$data[$coinId][$toLower];
        $change = isset($data[$coinId][$toLower . '_24h_change'])
                    ? (float)$data[$coinId][$toLower . '_24h_change']
                    : null;

        return [
            'price'     => $price,
            'change_24h'=> $change,
            'symbol'    => strtoupper($fromLower),
            'coin_id'   => $coinId,
            'vs'        => strtoupper($toLower),
        ];
    }

    /**
     * Format hasil harga crypto menjadi pesan Telegram (HTML).
     */
    private function formatPriceMsg(array $result): string {
        if (isset($result['error'])) {
            return "❌ " . $result['error']
                 . "\n\n<i>Contoh: <code>/price btc idr</code> atau <code>/price eth usd</code></i>";
        }

        $price    = $result['price'];
        $symbol   = $result['symbol'];
        $vs       = $result['vs'];
        $change   = $result['change_24h'];

        // Format angka
        if ($price >= 1000) {
            $priceStr = number_format($price, 2, '.', ',');
        } elseif ($price >= 1) {
            $priceStr = number_format($price, 4, '.', ',');
        } elseif ($price >= 0.0001) {
            $priceStr = number_format($price, 6, '.', ',');
        } else {
            $priceStr = number_format($price, 10, '.', ',');
            // hapus trailing zero berlebih
            $priceStr = rtrim(rtrim($priceStr, '0'), '.');
        }

        $changeStr = '';
        if ($change !== null) {
            $arrow     = $change >= 0 ? '▲' : '▼';
            $changeStr = "\n📊 24h: <b>$arrow " . number_format(abs($change), 2) . "%</b>";
        }

        $now = date('d/m/Y H:i') . ' UTC';

        return "💰 <b>$symbol / $vs</b>\n"
             . "💵 Harga: <code>$priceStr</code> $vs"
             . $changeStr . "\n"
             . "🕐 <i>$now</i>\n";            
    }

    /**
     * Parse argumen perintah /price.
     * Format yang didukung:
     *   /price btc            → BTC/USD
     *   /price btc idr        → BTC/IDR
     *   /price btc to idr     → BTC/IDR
     *   /price btc usdt       → BTC/USDT
     */
    private function parsePriceArgs(array $args): array {
        if (empty($args[0])) return ['', 'usd'];
        $from = strtolower(trim($args[0]));
        // Filter kata "to" / "ke" / "in" sebagai separator
        $rest = array_slice($args, 1);
        $rest = array_filter($rest, fn($a) => !in_array(strtolower($a), ['to', 'ke', 'in', '-']));
        $rest = array_values($rest);
        $to   = !empty($rest[0]) ? strtolower($rest[0]) : 'usd';
        return [$from, $to];
    }

    // ================================================================
    //  HELPER METHODS
    // ================================================================

    /**
     * Kirim pesan error target tidak ditemukan yang tepat.
     * - Jika targetMention berisi @username tapi targetId=0 → user ada tapi ID tidak bisa di-resolve
     * - Jika targetMention kosong → tidak ada target sama sekali
     */
    private function sendNoTargetError(int $targetId, string $targetMention): void {
        if ($targetId === 0 && str_starts_with($targetMention, '@')) {
            $username = ltrim($targetMention, '@');
            self::send($this->chatId, $this->t('error.username_not_found', ['username' => $username]));
        } else {
            self::send($this->chatId, $this->t('error.no_target'));
        }
    }

    private function parseCommand(string $text): array {
        if (empty($text) || $text[0] !== '/') return ['', []];
        $parts = explode(' ', $text, 2);
        $raw   = strtolower(ltrim($parts[0], '/'));
        $cmd   = explode('@', $raw)[0];
        $args  = isset($parts[1]) ? explode(' ', trim($parts[1])) : [];
        return [$cmd, $args];
    }

    private function resolveTarget(array $msg, array $args): array {
        $targetId      = 0;
        $targetMention = '';
        $reason        = $this->t('error.no_reason');
        $args2         = $args;

        // Prioritas 1: reply ke pesan — paling akurat
        if (!empty($msg['reply_to_message']['from'])) {
            $u             = $msg['reply_to_message']['from'];
            $targetId      = (int)$u['id'];
            $fn            = htmlspecialchars($u['first_name'] ?? '');
            $ln            = htmlspecialchars($u['last_name']  ?? '');
            $un            = $u['username'] ?? '';
            $targetMention = $un ? "@$un" : "<a href=\"tg://user?id=$targetId\">" . trim("$fn $ln") . "</a>";
            $reason        = implode(' ', $args) ?: $this->t('error.no_reason');
            if ($targetId) $this->db->cacheUser($targetId, $un, $u['first_name'] ?? '', $u['last_name'] ?? '');
            return [$targetId, $targetMention, $reason, $args2];
        }

        // Prioritas 2: cek entities pesan
        // — text_mention: user tanpa username (Telegram sertakan object user langsung)
        // — mention: @username biasa (extract dari teks, lalu resolve)
        $text = $msg['text'] ?? '';
        foreach ($msg['entities'] ?? [] as $e) {

            if ($e['type'] === 'text_mention' && !empty($e['user'])) {
                $u             = $e['user'];
                $targetId      = (int)$u['id'];
                $fn            = htmlspecialchars($u['first_name'] ?? '');
                $un            = $u['username'] ?? '';
                $targetMention = $un ? "@$un" : "<a href=\"tg://user?id=$targetId\">$fn</a>";
                $afterMention  = trim(substr($text, $e['offset'] + $e['length']));
                $reason        = $afterMention ?: $this->t('error.no_reason');
                $args2         = $afterMention ? explode(' ', $afterMention) : [];
                if ($targetId) $this->db->cacheUser($targetId, $un, $u['first_name'] ?? '', $u['last_name'] ?? '');
                return [$targetId, $targetMention, $reason, $args2];
            }

            if ($e['type'] === 'mention') {
                // Ambil @username dari teks menggunakan offset & length entity
                $rawMention    = substr($text, $e['offset'], $e['length']);       // e.g. "@john"
                $cleanUsername = strtolower(ltrim($rawMention, '@'));
                $targetMention = '@' . $cleanUsername;
                $afterMention  = trim(substr($text, $e['offset'] + $e['length']));
                $reason        = $afterMention ?: $this->t('error.no_reason');
                $args2         = $afterMention ? explode(' ', $afterMention) : [];

                // Coba resolve user_id: 1) cache DB, 2) Telegram API
                $targetId = $this->db->getUserIdByUsername($cleanUsername);
                if (!$targetId) {
                    $targetId = $this->resolveUsernameViaApi($cleanUsername);
                }
                return [$targetId, $targetMention, $reason, $args2];
            }
        }

        // Prioritas 3: argumen teks setelah command
        // Mendukung: /kick 123456, /kick @username, /kick username
        if (!empty($args[0])) {
            $firstArg = $args[0];

            if (is_numeric($firstArg)) {
                // Numeric user ID langsung
                $targetId      = (int)$firstArg;
                $targetMention = "User <code>$targetId</code>";
                $reason        = implode(' ', array_slice($args, 1)) ?: $this->t('error.no_reason');
                $args2         = array_slice($args, 1);

            } else {
                // @username ATAU username tanpa @ (min 5 karakter, hanya huruf/angka/underscore)
                $cleanUsername = strtolower(ltrim($firstArg, '@'));
                if (preg_match('/^[a-zA-Z0-9_]{5,32}$/', $cleanUsername)) {
                    $targetMention = '@' . $cleanUsername;
                    $reason        = implode(' ', array_slice($args, 1)) ?: $this->t('error.no_reason');
                    $args2         = array_slice($args, 1);

                    // Coba resolve user_id: 1) cache DB, 2) Telegram API
                    $targetId = $this->db->getUserIdByUsername($cleanUsername);
                    if (!$targetId) {
                        $targetId = $this->resolveUsernameViaApi($cleanUsername);
                    }
                }
            }
        }

        return [$targetId, $targetMention, $reason, $args2];
    }

    /**
     * Resolve @username ke user_id via Telegram API.
     *
     * Strategi:
     * 1. getChatMember dengan @username sebagai user_id (beberapa Telegram server mendukung)
     * 2. Scan getChatAdministrators — cari username di daftar admin
     * 3. Jika berhasil, simpan ke cache untuk request berikutnya
     *
     * Return 0 jika tidak berhasil di-resolve.
     */
    private function resolveUsernameViaApi(string $username): int {
        $clean = strtolower(ltrim($username, '@'));
        if (!$clean) return 0;

        // Strategi 1: getChatMember — Telegram Bot API kadang menerima @username sebagai user_id
        $r = self::api('getChatMember', [
            'chat_id' => $this->chatId,
            'user_id' => '@' . $clean,
        ]);

        if (!empty($r['result']['user']['id'])) {
            $u  = $r['result']['user'];
            $id = (int)$u['id'];
            $this->db->cacheUser($id, $u['username'] ?? '', $u['first_name'] ?? '', $u['last_name'] ?? '');
            return $id;
        }

        // Strategi 2: scan daftar administrator grup
        // Berguna untuk demote/settitle/dll pada admin yang belum pernah kirim pesan
        $admins = self::api('getChatAdministrators', ['chat_id' => $this->chatId]);
        foreach ($admins['result'] ?? [] as $member) {
            $u = $member['user'] ?? [];
            if (!empty($u['username']) && strtolower($u['username']) === $clean) {
                $id = (int)$u['id'];
                $this->db->cacheUser($id, $u['username'] ?? '', $u['first_name'] ?? '', $u['last_name'] ?? '');
                return $id;
            }
        }

        return 0;
    }

    private function parseDuration(string $str): int {
        if (!$str) return 0;
        if (!preg_match('/^(\d+)(m|h|d)$/', strtolower($str), $m)) return 0;
        $mult = ['m' => 60, 'h' => 3600, 'd' => 86400];
        return time() + ((int)$m[1] * $mult[$m[2]]);
    }

    /**
     * Cek apakah pesan mengandung link — termasuk entities tersembunyi, caption, dll.
     * Mendeteksi: URL biasa, text_link (hyperlink tersembunyi), t.me/ links, inline mentions.
     */
    private function messageHasLink(array $msg, array $whitelist): bool {
        $urls = [];

        // Cek entities di text
        $text = $msg['text'] ?? '';
        foreach ($msg['entities'] ?? [] as $e) {
            if ($e['type'] === 'url') {
                $urls[] = substr($text, $e['offset'], $e['length']);
            } elseif ($e['type'] === 'text_link') {
                $urls[] = $e['url'] ?? '';
            }
            // 'mention' (@username) dan 'text_mention' (user tanpa username) umumnya diabaikan
            // tapi jika ingin lebih ketat, tambahkan di sini
        }

        // Cek caption_entities (untuk foto/video/dokumen dengan caption berisi link)
        $caption = $msg['caption'] ?? '';
        foreach ($msg['caption_entities'] ?? [] as $e) {
            if ($e['type'] === 'url') {
                $urls[] = substr($caption, $e['offset'], $e['length']);
            } elseif ($e['type'] === 'text_link') {
                $urls[] = $e['url'] ?? '';
            }
        }

        // Cek teks & caption mentah dengan regex (URL yang tidak terdeteksi sebagai entity)
        $combined = trim($text . ' ' . $caption);
        if ($combined !== '') {
            if (preg_match_all(
                '~(https?://[^\s<>"]+|t\.me/[^\s<>"]+|telegram\.me/[^\s<>"]+|bit\.ly/[^\s<>"]+|tinyurl\.com/[^\s<>"]+|vk\.cc/[^\s<>"]+)~i',
                $combined,
                $matches
            )) {
                foreach ($matches[0] as $m) $urls[] = $m;
            }
        }

        // Cek button inline yang mengandung URL (forward channel dengan tombol)
        if (!empty($msg['reply_markup']['inline_keyboard'])) {
            foreach ($msg['reply_markup']['inline_keyboard'] as $row) {
                foreach ($row as $btn) {
                    if (!empty($btn['url'])) $urls[] = $btn['url'];
                }
            }
        }

        // Tidak ada URL ditemukan sama sekali
        if (empty($urls)) return false;

        // Whitelist: jika URL mengandung domain yang di-whitelist, izinkan
        foreach ($urls as $url) {
            if (empty(trim($url))) continue;
            $isWhitelisted = false;
            foreach ($whitelist as $wl) {
                if ($wl && stripos($url, $wl) !== false) {
                    $isWhitelisted = true;
                    break;
                }
            }
            if (!$isWhitelisted) return true; // Ada URL yang tidak di-whitelist → blokir
        }

        return false; // Semua URL di-whitelist
    }

    /**
     * Kick member yang captcha-nya sudah expired (lazy check saat ada pesan masuk)
     * Cek semua grup (global) dengan throttle 30 detik agar tidak boros resource.
     */
    private function checkExpiredCaptchas(): void {
        $now = time();

        // Throttle: jalankan global check maksimal sekali per 30 detik
        $lastRun = (int)($this->db->fetchOne(
            "SELECT value FROM bot_settings WHERE key = 'last_captcha_check'"
        )['value'] ?? 0);

        if ($now - $lastRun < 30) {
            return;
        }

        // Update timestamp sebelum proses agar request paralel tidak dobel-proses
        $this->db->execute(
            "INSERT INTO bot_settings (key, value) VALUES ('last_captcha_check', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$now]
        );

        // Ambil SEMUA captcha expired dari semua grup (bukan hanya chatId saat ini)
        $expired = $this->db->query(
            "SELECT * FROM captcha_pending WHERE expires_at < ?",
            [$now]
        );

        foreach ($expired as $cap) {
            $chatId  = (int)$cap['chat_id'];
            $uid     = (int)$cap['user_id'];
            $rawName = !empty($cap['first_name']) ? htmlspecialchars($cap['first_name']) : "#$uid";
            $mention = "<a href=\"tg://user?id=$uid\">$rawName</a>";

            // Hapus pesan notifikasi captcha di grup
            if ($cap['message_id']) self::del($chatId, (int)$cap['message_id']);
            // Hapus pesan captcha di DM user (jika dikirim via DM)
            if (!empty($cap['dm_message_id'])) self::del($uid, (int)$cap['dm_message_id']);

            // Kick user
            $kicked = self::api('banChatMember', [
                'chat_id'    => $chatId,
                'user_id'    => $uid,
                'until_date' => $now + 40,
            ]);

            if ($kicked['ok'] ?? false) {
                // Muat bahasa grup yang bersangkutan
                $gs = $this->db->getGroupSettings($chatId);
                Lang::load($gs['language'] ?? 'id');
                self::api('sendMessage', [
                    'chat_id'    => $chatId,
                    'text'       => Lang::t('captcha.expired_msg', ['mention' => $mention]),
                    'parse_mode' => 'HTML',
                ]);
            } else {
                self::api('sendMessage', [
                    'chat_id'    => $chatId,
                    'text'       => "⚠️ Bot tidak dapat kick <a href=\"tg://user?id=$uid\">$rawName</a> karena kurang izin <b>Ban Members</b>.",
                    'parse_mode' => 'HTML',
                ]);
            }

            // Hapus dari DB setelah proses (kick berhasil atau tidak)
            $this->db->execute(
                "DELETE FROM captcha_pending WHERE chat_id = ? AND user_id = ?",
                [$chatId, $uid]
            );
        }
    }

    private function fmtWelcome(string $tpl, array $user, array $chat): string {
        $fn   = htmlspecialchars($user['first_name'] ?? '');
        $ln   = htmlspecialchars($user['last_name']  ?? '');
        $un   = $user['username'] ?? '';
        $id   = $user['id'] ?? 0;
        $name = trim("$fn $ln");
        $mn   = $un ? "@$un" : "<a href=\"tg://user?id=$id\">$name</a>";
        $grp  = htmlspecialchars($chat['title'] ?? '');
        $cnt  = self::getMemberCount($this->chatId);
        return str_replace(
            ['{name}', '{mention}', '{group}', '{id}', '{count}', '{username}'],
            [$name, $mn, $grp, $id, $cnt, "@$un"],
            $tpl
        );
    }

    private function sendNote(array $note): void {
        $content  = $note['content'];
        $fileId   = $note['file_id']   ?? '';
        $fileType = $note['file_type']  ?? '';
        if ($fileId && $fileType) {
            $method = ['photo' => 'sendPhoto', 'video' => 'sendVideo', 'document' => 'sendDocument', 'audio' => 'sendAudio', 'sticker' => 'sendSticker'][$fileType] ?? 'sendDocument';
            $field  = ['photo' => 'photo', 'video' => 'video', 'document' => 'document', 'audio' => 'audio', 'sticker' => 'sticker'][$fileType] ?? 'document';
            $p      = ['chat_id' => $this->chatId, $field => $fileId];
            if ($content) $p['caption'] = $content;
            self::api($method, $p);
        } else {
            self::send($this->chatId, $content);
        }
    }

    private function checkFilters(string $text): void {
        $filters = $this->db->getFilters($this->chatId);
        foreach ($filters as $f) {
            if (stripos($text, $f['keyword']) !== false) {
                self::send($this->chatId, $f['response']);
                return;
            }
        }
    }

    private function sendGroup(string $text, array $extra = []): array {
        $r = self::send($this->chatId, $text, $extra);
        $mid = $r['result']['message_id'] ?? 0;
        if ($mid) $this->db->logMessageId($this->chatId, $mid);
        return $r;
    }

    private function sendCaptcha(int $userId, array $user, int $timeout = 60, string $rules = '', string $type = 'math'): void {
        $fn     = htmlspecialchars($user['first_name'] ?? 'User');
        $expiry = time() + $timeout;

        if ($type === 'hcaptcha') {
            $this->sendHcaptcha($userId, $user, $fn, $timeout, $expiry, $rules);
            return;
        }

        // === MATH CAPTCHA ===
        $a       = rand(1, 15);
        $b       = rand(1, 15);
        $ans     = $a + $b;
        $expTime = date('H:i:s', $expiry);
        $token   = bin2hex(random_bytes(16));

        // Buat 4 pilihan jawaban unik (1 benar, 3 salah)
        $opts     = [$ans];
        $attempts = 0;
        while (count($opts) < 4 && $attempts < 30) {
            $offset = rand(1, 6) * (rand(0, 1) ? 1 : -1);
            $fake   = $ans + $offset;
            if ($fake > 0 && $fake !== $ans && !in_array($fake, $opts)) $opts[] = $fake;
            $attempts++;
        }
        while (count($opts) < 4) {
            $fake = rand(1, 30);
            if (!in_array($fake, $opts)) $opts[] = $fake;
        }
        shuffle($opts);

        $kb = ['inline_keyboard' => [
            array_map(fn($v) => ['text' => "  $v  ", 'callback_data' => "captcha_verify:$v"], array_slice($opts, 0, 2)),
            array_map(fn($v) => ['text' => "  $v  ", 'callback_data' => "captcha_verify:$v"], array_slice($opts, 2, 2)),
        ]];

        $rulesNote = !empty($rules) ? "\n\n" . $this->t('captcha.rules_note') : '';
        $caption   = $this->t('captcha.image_caption', [
            'name'       => $fn,
            'timeout'    => $timeout,
            'time'       => $expTime,
            'rules_note' => $rulesNote,
        ]);

        $mention = "<a href=\"tg://user?id={$userId}\">{$fn}</a>";
        $imgData = CaptchaImage::generate($a, $b);

        // Coba kirim soal captcha ke DM user (lebih aman — tidak bisa dijawab orang lain)
        $dmResult  = self::sendPhoto($userId, $imgData, [
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($kb),
        ]);
        $dmSuccess  = !empty($dmResult['result']['message_id']);
        $dmMsgId    = (int)($dmResult['result']['message_id'] ?? 0);

        if ($dmSuccess) {
            // DM berhasil — kirim notifikasi singkat ke grup (tanpa soal captcha)
            $r = self::send($this->chatId, $this->t('captcha.math_dm_sent', [
                'mention' => $mention,
                'timeout' => $timeout,
                'time'    => $expTime,
            ]));
        } else {
            // DM gagal (user belum start bot) — kirim ke grup dengan deep link
            $deepLink = 'https://t.me/' . BOT_USERNAME . '?start=mathcap_' . $token;
            $startKb  = ['inline_keyboard' => [[
                ['text' => $this->t('captcha.hcaptcha_start_btn'), 'url' => $deepLink],
            ]]];
            $r = self::send($this->chatId, $this->t('captcha.math_start_msg', [
                'mention' => $mention,
                'timeout' => $timeout,
                'time'    => $expTime,
            ]), ['reply_markup' => json_encode($startKb)]);
        }

        $mid = $r['result']['message_id'] ?? 0;
        if ($mid) $this->db->logMessageId($this->chatId, $mid);

        $this->db->execute(
            "INSERT OR REPLACE INTO captcha_pending (chat_id,user_id,answer,message_id,expires_at,first_name,hcaptcha_token,dm_message_id) VALUES(?,?,?,?,?,?,?,?)",
            [$this->chatId, $userId, $ans, $mid, $expiry, $user['first_name'] ?? '', $token, $dmMsgId]
        );
    }

    private function sendHcaptcha(int $userId, array $user, string $fn, int $timeout, int $expiry, string $rules = ''): void {
        $token   = bin2hex(random_bytes(20));
        $expTime = date('H:i:s', $expiry);

        // Buat URL verifikasi
        $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $verifyUrl = "$scheme://$host/bot/captcha_web.php?token=$token";

        $rulesNote = !empty($rules) ? "\n\n" . $this->t('captcha.rules_note') : '';
        $dmText    = $this->t('captcha.hcaptcha_msg', [
            'name'       => $fn,
            'timeout'    => $timeout,
            'time'       => $expTime,
            'rules_note' => $rulesNote,
        ]);

        $verifyKb = ['inline_keyboard' => [[
            ['text' => $this->t('captcha.hcaptcha_btn'), 'url' => $verifyUrl],
        ]]];

        $mention = "<a href=\"tg://user?id={$userId}\">{$fn}</a>";

        // Coba kirim link verifikasi ke DM user (lebih aman — link tidak terlihat di grup)
        $dmResult  = self::api('sendMessage', [
            'chat_id'      => $userId,
            'text'         => $dmText,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($verifyKb),
        ]);
        $dmSuccess = !empty($dmResult['result']['message_id']);
        $dmMsgId   = (int)($dmResult['result']['message_id'] ?? 0);

        if ($dmSuccess) {
            // DM berhasil — kirim notifikasi singkat ke grup (tanpa link)
            $r = self::send($this->chatId, $this->t('captcha.hcaptcha_dm_sent', [
                'mention' => $mention,
                'timeout' => $timeout,
                'time'    => $expTime,
            ]));
        } else {
            // DM gagal (user belum pernah mulai bot) — kirim ke grup dengan deep link tombol "Mulai Bot"
            $deepLink = 'https://t.me/' . BOT_USERNAME . '?start=hcaptcha_' . $token;
            $startKb  = ['inline_keyboard' => [[
                ['text' => $this->t('captcha.hcaptcha_start_btn'), 'url' => $deepLink],
            ]]];
            $r = self::send($this->chatId, $this->t('captcha.hcaptcha_start_msg', [
                'mention' => $mention,
                'timeout' => $timeout,
                'time'    => $expTime,
            ]), ['reply_markup' => json_encode($startKb)]);
        }

        $mid = $r['result']['message_id'] ?? 0;
        if ($mid) $this->db->logMessageId($this->chatId, $mid);

        $this->db->execute(
            "INSERT OR REPLACE INTO captcha_pending (chat_id,user_id,answer,message_id,expires_at,first_name,hcaptcha_token,dm_message_id) VALUES(?,?,?,?,?,?,?,?)",
            [$this->chatId, $userId, 0, $mid, $expiry, $user['first_name'] ?? '', $token, $dmMsgId]
        );
    }

    private function doFlood(array $settings, int $msgId): void {
        $act = $settings['antiflood_action'];
        $dur = (int)($settings['antiflood_duration'] ?? 300);
        self::del($this->chatId, $msgId);
        $mn = $this->userMention;
        if ($act === 'mute') { self::mute($this->chatId, $this->userId, time() + $dur); self::send($this->chatId, $this->t('flood.muted', ['mention' => $mn, 'dur' => $dur])); }
        if ($act === 'kick') { self::kick($this->chatId, $this->userId); self::send($this->chatId, $this->t('flood.kicked', ['mention' => $mn])); }
        if ($act === 'ban')  { self::ban($this->chatId, $this->userId); self::send($this->chatId, $this->t('flood.banned', ['mention' => $mn])); $this->db->incrementStats($this->chatId, 'total_banned'); }
        $this->recordViolation($settings, 'Anti-Flood');
    }

    /**
     * Catat 1 poin sanksi untuk user yang melanggar aturan grup.
     * Jika poin mencapai batas (violation_limit), eksekusi aksi yang dikonfigurasi admin.
     */
    private function recordViolation(array $settings, string $reason): void {
        if (!($settings['violation_enabled'] ?? 0)) return;

        $limit  = max(1, (int)($settings['violation_limit'] ?? 5));
        $action = $settings['violation_action'] ?? 'kick';
        $points = $this->db->addViolationPoint($this->chatId, $this->userId);

        if ($points >= $limit) {
            // Batas tercapai — eksekusi aksi dan reset poin
            $this->db->resetViolationPoints($this->chatId, $this->userId);
            $actionLabel = '';
            switch ($action) {
                case 'kick':
                    self::kick($this->chatId, $this->userId);
                    $actionLabel = $this->t('action.kicked');
                    break;
                case 'ban':
                    self::ban($this->chatId, $this->userId);
                    $this->db->incrementStats($this->chatId, 'total_banned');
                    $actionLabel = $this->t('action.banned');
                    break;
                case 'mute':
                    self::mute($this->chatId, $this->userId);
                    $this->db->incrementStats($this->chatId, 'total_muted');
                    $actionLabel = $this->t('action.muted');
                    break;
            }
            self::send($this->chatId,
                $this->t('vp.limit_reached', ['mention' => $this->userMention, 'action' => $actionLabel, 'limit' => $limit, 'reason' => $reason])
            );
        } else {
            // Informasikan poin saat ini
            self::send($this->chatId,
                $this->t('vp.point_added', ['mention' => $this->userMention, 'reason' => $reason, 'points' => $points, 'limit' => $limit])
                . ($points === $limit - 1 ? "\n" . $this->t('vp.warning_text', ['action' => $action]) : '')
            );
        }
    }

    private function syncGroupInfo(array $chat): void {
        $this->db->execute(
            "INSERT INTO group_settings (chat_id,title,username,updated_at) VALUES(?,?,?,strftime('%s','now'))
             ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title,username=excluded.username,updated_at=strftime('%s','now')",
            [$chat['id'], $chat['title'] ?? '', $chat['username'] ?? '']
        );
    }

    // ================================================================
    //  TEXT BUILDERS
    // ================================================================

    private function txtStart(): string {
        return $this->t('start.text', ['name' => $this->userFullName, 'sa' => '']);
    }

    private function resolveHelpCategory(string $raw): string {
        $k = strtolower(trim($raw));
        return match ($k) {
            'general', 'umum', 'info'                                      => 'general',
            'admin', 'mod', 'moderation', 'moderasi'                       => 'admin',
            'content', 'konten', 'lock', 'locks', 'note', 'notes', 'filter', 'filters' => 'content',
            'safety', 'sanksi', 'violation', 'sangmata', 'sang_mata', 'votekick' => 'safety',
            'ai', 'tools', 'tool', 'utilitas', 'run', 'price', 'crypto'    => 'ai',
            'settings', 'setting', 'pengaturan', 'grup', 'group'           => 'settings',
            'sa', 'superadmin', 'hosting', 'cpanel', 'whm', 'email', 'tempmail' => 'sa',
            default                                                        => 'overview',
        };
    }

    private function txtHelp(string $cat = 'overview'): string {
        $cat = $this->resolveHelpCategory($cat);
        return match ($cat) {
            'general'  => $this->t('help.title') . $this->t('help.general'),
            'admin'    => $this->t('help.title') . $this->t('help.admin'),
            'content'  => $this->t('help.title') . $this->t('help.lock') . $this->t('help.note') . $this->t('help.filter'),
            'safety'   => $this->t('help.title') . $this->t('help.violation') . $this->t('help.sang_mata') . $this->t('help.votekick'),
            'ai'       => $this->t('help.title') . $this->t('help.ai') . $this->t('help.tools'),
            'settings' => $this->t('help.title') . $this->t('help.settings'),
            'sa'       => self::isSA($this->userId)
                            ? $this->t('help.title') . $this->t('help.sa')
                            : $this->t('help.sa_restricted'),
            default    => $this->t('help.title')
                            . $this->t('help.overview')
                            . (self::isSA($this->userId) ? $this->t('help.overview_sa') : ''),
        };
    }

    private function kbHelp(string $activeCat = 'overview', bool $isPrivate = true): array {
        $mark = fn(string $cat, string $labelKey): string =>
            ($activeCat === $cat ? '✅ ' : '') . $this->t($labelKey);

        $rows = [
            [
                ['text' => $mark('general', 'help.btn_general'),   'callback_data' => 'help_cat:general'],
                ['text' => $mark('admin',   'help.btn_admin'),     'callback_data' => 'help_cat:admin'],
            ],
            [
                ['text' => $mark('content', 'help.btn_content'),   'callback_data' => 'help_cat:content'],
                ['text' => $mark('safety',  'help.btn_safety'),    'callback_data' => 'help_cat:safety'],
            ],
            [
                ['text' => $mark('ai',       'help.btn_ai'),       'callback_data' => 'help_cat:ai'],
                ['text' => $mark('settings', 'help.btn_settings'), 'callback_data' => 'help_cat:settings'],
            ],
        ];
        if (self::isSA($this->userId)) {
            $rows[] = [
                ['text' => $mark('sa', 'help.btn_sa'), 'callback_data' => 'help_cat:sa'],
            ];
        }
        $lastRow = [];
        if ($activeCat !== 'overview') {
            $lastRow[] = ['text' => $this->t('help.btn_overview'), 'callback_data' => 'help_cat:overview'];
        }
        $lastRow[] = ['text' => $this->t('help.btn_features'), 'callback_data' => 'menu_features'];
        $lastRow[] = $isPrivate
            ? ['text' => $this->t('kb.back'), 'callback_data' => 'menu_main']
            : ['text' => $this->t('help.btn_close'), 'callback_data' => 'close_msg'];
        $rows[] = $lastRow;
        return ['inline_keyboard' => $rows];
    }

    private function txtFeatures(): string {
        return $this->t('features.title') . $this->t('features.list');
    }

    private function txtAbout(): string {
        return $this->t('about.text');
    }

    private function txtSA(): string {
        $stats   = $this->db->getGlobalStats();
        $maint   = $this->db->getBotSetting('maintenance_mode', '0');
        $dynSA   = $stats['total_dyn_sa'];
        $totalSA = count(SUPERADMINS) + $dynSA;
        $maintTxt = $maint === '1' ? $this->t('sa.maint_on') : $this->t('sa.maint_off');
        return $this->t('sa.panel_title', [
            'id'        => $this->userId,
            'time'      => date('d/m/Y H:i:s'),
            'groups'    => $stats['total_groups'],
            'users'     => $stats['total_users'],
            'gban'      => $stats['total_gban'],
            'broadcast' => $stats['total_broadcast'],
            'total_sa'  => $totalSA,
            'static_sa' => count(SUPERADMINS),
            'dyn_sa'    => $dynSA,
            'maint'     => $maintTxt,
        ]);
    }

    private function txtSysInfo(): string {
        // ── OS & KERNEL ─────────────────────────────────────────────────────
        $uname       = php_uname();
        $osName      = php_uname('s');   // Linux
        $hostName    = php_uname('n');   // server hostname
        $kernelRel   = php_uname('r');   // kernel release
        $kernelVer   = php_uname('v');   // kernel version string
        $machine     = php_uname('m');   // x86_64 etc.

        // Distro info from /etc/os-release if available
        $distro = 'N/A';
        if (is_readable('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');
            $distro    = $osRelease['PRETTY_NAME'] ?? ($osRelease['NAME'] ?? 'N/A');
        } elseif (is_readable('/etc/issue')) {
            $distro = trim(file_get_contents('/etc/issue'));
            $distro = preg_replace('/\\\\[nrlm]/', '', $distro);
            $distro = trim($distro);
        }

        // ── UPTIME ──────────────────────────────────────────────────────────
        $uptimeStr = 'N/A';
        if (is_readable('/proc/uptime')) {
            $uptimeSec = (float)explode(' ', file_get_contents('/proc/uptime'))[0];
            $days  = (int)floor($uptimeSec / 86400);
            $hours = (int)floor(fmod($uptimeSec, 86400) / 3600);
            $mins  = (int)floor(fmod($uptimeSec, 3600) / 60);
            $uptimeStr = ($days > 0 ? "{$days}h " : '') . "{$hours}j {$mins}m";
        }

        // ── CPU ─────────────────────────────────────────────────────────────
        $cpuModel = 'N/A';
        $cpuCores = 'N/A';
        $cpuFreq  = 'N/A';
        if (is_readable('/proc/cpuinfo')) {
            $cpuInfo  = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $m)) $cpuModel = trim($m[1]);
            $cpuCores = substr_count($cpuInfo, 'processor');
            if (preg_match('/cpu MHz\s*:\s*([\d.]+)/i', $cpuInfo, $m)) {
                $cpuFreq = round((float)$m[1]) . ' MHz';
            }
        }

        // ── LOAD AVERAGE ────────────────────────────────────────────────────
        $loadStr = 'N/A';
        if (function_exists('sys_getloadavg')) {
            $load    = sys_getloadavg();
            $loadStr = number_format($load[0], 2) . ' / '
                     . number_format($load[1], 2) . ' / '
                     . number_format($load[2], 2)
                     . " ({$cpuCores} core)";
        }

        // ── MEMORY (RAM) ─────────────────────────────────────────────────────
        $memTotal = $memFree = $memAvail = $memBuffers = $memCached = $swapTotal = $swapFree = 0;
        if (is_readable('/proc/meminfo')) {
            foreach (file('/proc/meminfo') as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $mi)) {
                    switch ($mi[1]) {
                        case 'MemTotal':     $memTotal   = (int)$mi[2]; break;
                        case 'MemFree':      $memFree    = (int)$mi[2]; break;
                        case 'MemAvailable': $memAvail   = (int)$mi[2]; break;
                        case 'Buffers':      $memBuffers = (int)$mi[2]; break;
                        case 'Cached':       $memCached  = (int)$mi[2]; break;
                        case 'SwapTotal':    $swapTotal  = (int)$mi[2]; break;
                        case 'SwapFree':     $swapFree   = (int)$mi[2]; break;
                    }
                }
            }
        }
        $fmtKB = fn(int $kb): string => $kb >= 1048576
            ? number_format($kb / 1048576, 2) . ' GB'
            : number_format($kb / 1024, 1) . ' MB';
        $memUsed   = $memTotal - $memAvail;
        $memPct    = $memTotal > 0 ? round(($memUsed / $memTotal) * 100) : 0;
        $swapUsed  = $swapTotal - $swapFree;
        $swapPct   = $swapTotal > 0 ? round(($swapUsed / $swapTotal) * 100) : 0;
        $ramBar    = $this->sysBar($memPct);
        $swapBar   = $swapTotal > 0 ? $this->sysBar($swapPct) : 'Tidak tersedia';

        // ── DISK ────────────────────────────────────────────────────────────
        $diskTotal = disk_total_space(__DIR__);
        $diskFree  = disk_free_space(__DIR__);
        $diskUsed  = $diskTotal - $diskFree;
        $diskPct   = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100) : 0;
        $diskBar   = $this->sysBar($diskPct);
        $fmtB      = fn(float $b): string => $b >= 1073741824
            ? number_format($b / 1073741824, 2) . ' GB'
            : number_format($b / 1048576, 1) . ' MB';

        // ── PHP INFO ────────────────────────────────────────────────────────
        $phpVer       = PHP_VERSION;
        $phpSapi      = PHP_SAPI;
        $phpMaxExec   = ini_get('max_execution_time') . 's';
        $phpMemLimit  = ini_get('memory_limit');
        $phpUploadMax = ini_get('upload_max_filesize');
        $phpPostMax   = ini_get('post_max_size');
        $phpExtList   = ['curl', 'pdo_sqlite', 'mbstring', 'json', 'openssl', 'gd', 'zip', 'imap'];
        $phpExts      = [];
        foreach ($phpExtList as $ext) {
            $phpExts[] = ($ext . ':' . (extension_loaded($ext) ? '✅' : '❌'));
        }
        $phpDisabled  = ini_get('disable_functions') ?: 'Tidak ada';

        // ── SERVER SOFTWARE ─────────────────────────────────────────────────
        $serverSw  = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
        $serverIP  = $_SERVER['SERVER_ADDR']     ?? gethostbyname($hostName);
        $serverPort= $_SERVER['SERVER_PORT']     ?? 'N/A';
        $docRoot   = $_SERVER['DOCUMENT_ROOT']   ?? __DIR__;
        $scriptDir = __DIR__;

        // ── NETWORK ─────────────────────────────────────────────────────────
        $publicIP  = 'N/A';
        $curlIP    = curl_init('https://api.ipify.org?format=json');
        if ($curlIP) {
            curl_setopt_array($curlIP, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $ipResp = curl_exec($curlIP);
            curl_close($curlIP);
            if ($ipResp) {
                $ipData   = json_decode($ipResp, true);
                $publicIP = $ipData['ip'] ?? 'N/A';
            }
        }

        // Cek open port yang umum digunakan (TCP connect cepat)
        $portsToCheck = [80 => 'HTTP', 443 => 'HTTPS', 21 => 'FTP', 22 => 'SSH',
                         25 => 'SMTP', 110 => 'POP3', 143 => 'IMAP',
                         3306 => 'MySQL', 5432 => 'PostgreSQL', 8080 => 'HTTP-Alt'];
        $openPorts = [];
        foreach ($portsToCheck as $port => $label) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($sock) { fclose($sock); $openPorts[] = "$port ($label)"; }
        }
        $openPortsStr = empty($openPorts) ? 'Tidak ada / tidak bisa dicek' : implode(', ', $openPorts);

        // ── BOT-SPECIFIC ────────────────────────────────────────────────────
        $dbPath    = DB_PATH;
        $dbSizeStr = is_file($dbPath) ? $fmtB((float)filesize($dbPath)) : 'N/A';
        $logPath   = LOG_PATH;
        $logSizeStr= is_file($logPath) ? $fmtB((float)filesize($logPath)) : '0 B';
        $timezone  = date_default_timezone_get();
        $nowLocal  = date('d/m/Y H:i:s T');
        $phpPid    = getmypid();
        $phpMem    = memory_get_usage(true);
        $phpMemPeak= memory_get_peak_usage(true);

        // Webhook info
        $webhookInfo = self::api('getWebhookInfo');
        $whUrl       = $webhookInfo['result']['url']                     ?? 'Tidak diset';
        $whPending   = $webhookInfo['result']['pending_update_count']    ?? 0;
        $whLastErr   = $webhookInfo['result']['last_error_message']      ?? 'Tidak ada';
        $whLastDate  = isset($webhookInfo['result']['last_error_date']) && $webhookInfo['result']['last_error_date'] > 0
            ? date('d/m/Y H:i:s', $webhookInfo['result']['last_error_date']) : 'N/A';
        $whMaxConn   = $webhookInfo['result']['max_connections']         ?? 'N/A';

        // ── PROSES AKTIF ────────────────────────────────────────────────────
        $processCount = 'N/A';
        if (is_readable('/proc')) {
            $processCount = count(glob('/proc/[0-9]*', GLOB_ONLYDIR));
        }

        // ── PRA-KOMPUTASI NILAI TERFORMAT ───────────────────────────────────
        $memTotalFmt   = $fmtKB($memTotal);
        $memUsedFmt    = $fmtKB($memUsed);
        $memAvailFmt   = $fmtKB($memAvail);
        $swapTotalFmt  = $fmtKB($swapTotal);
        $swapUsedFmt   = $fmtKB($swapUsed);
        $diskTotalFmt  = $fmtB($diskTotal);
        $diskUsedFmt   = $fmtB($diskUsed);
        $diskFreeFmt   = $fmtB($diskFree);
        $phpMemFmt     = $fmtB((float)$phpMem);
        $phpMemPeakFmt = $fmtB((float)$phpMemPeak);

        // ── BUILD OUTPUT ────────────────────────────────────────────────────
        $t  = $this->t('sa.sysinfo_title');
        $t .= "\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🖥️ <b>OS &amp; SERVER</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🐧 OS/Distro: <code>" . htmlspecialchars($distro) . "</code>\n";
        $t .= "🔖 Kernel: <code>" . htmlspecialchars($kernelRel) . " ({$machine})</code>\n";
        $t .= "🏠 Hostname: <code>" . htmlspecialchars($hostName) . "</code>\n";
        $t .= "🌐 IP Publik: <code>{$publicIP}</code>\n";
        $t .= "🔌 IP Server: <code>{$serverIP}</code>\n";
        $t .= "⚙️ Web Server: <code>" . htmlspecialchars($serverSw) . "</code>\n";
        $t .= "📂 Doc Root: <code>" . htmlspecialchars($docRoot) . "</code>\n";
        $t .= "📂 Bot Dir: <code>" . htmlspecialchars($scriptDir) . "</code>\n";
        $t .= "⏱️ Uptime: <b>{$uptimeStr}</b>\n";
        $t .= "🔄 Proses Aktif: <b>{$processCount}</b>\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🧠 <b>CPU</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "💻 Model: <code>" . htmlspecialchars($cpuModel) . "</code>\n";
        $t .= "🔢 Core: <b>{$cpuCores}</b>  |  Frekuensi: <b>{$cpuFreq}</b>\n";
        $t .= "📊 Load Avg (1/5/15 mnt): <b>{$loadStr}</b>\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "💾 <b>MEMORI (RAM)</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "📦 Total: <b>{$memTotalFmt}</b>\n";
        $t .= "✅ Terpakai: <b>{$memUsedFmt}</b> ({$memPct}%)\n";
        $t .= "🟢 Tersedia: <b>{$memAvailFmt}</b>\n";
        $t .= "📈 {$ramBar} {$memPct}%\n";
        $t .= "🔁 Swap Total: <b>{$swapTotalFmt}</b>";
        if ($swapTotal > 0) {
            $t .= " | Terpakai: <b>{$swapUsedFmt}</b> ({$swapPct}%)\n";
            $t .= "📈 {$swapBar} {$swapPct}%\n";
        } else {
            $t .= " (tidak aktif)\n";
        }
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "💿 <b>DISK</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "📦 Total: <b>{$diskTotalFmt}</b>\n";
        $t .= "✅ Terpakai: <b>{$diskUsedFmt}</b> ({$diskPct}%)\n";
        $t .= "🟢 Bebas: <b>{$diskFreeFmt}</b>\n";
        $t .= "📈 {$diskBar} {$diskPct}%\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🐘 <b>PHP</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🔖 Versi: <b>PHP {$phpVer}</b>  |  SAPI: <code>{$phpSapi}</code>\n";
        $t .= "⏱️ Max Exec: <b>{$phpMaxExec}</b>  |  Mem Limit: <b>{$phpMemLimit}</b>\n";
        $t .= "📤 Upload Max: <b>{$phpUploadMax}</b>  |  POST Max: <b>{$phpPostMax}</b>\n";
        $t .= "🔧 Ext: " . implode('  ', $phpExts) . "\n";
        $t .= "🚫 Disabled Func: <code>" . htmlspecialchars(strlen($phpDisabled) > 100 ? substr($phpDisabled, 0, 100) . '...' : $phpDisabled) . "</code>\n";
        $t .= "🧩 PID: <code>{$phpPid}</code>  |  Mem: <b>{$phpMemFmt}</b>  |  Peak: <b>{$phpMemPeakFmt}</b>\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🌐 <b>JARINGAN</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🔓 Port Terbuka (lokal): <code>" . htmlspecialchars($openPortsStr) . "</code>\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🤖 <b>BOT &amp; WEBHOOK</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🔗 Webhook URL: <code>" . htmlspecialchars($whUrl) . "</code>\n";
        $t .= "📬 Pending Update: <b>{$whPending}</b>  |  Max Conn: <b>{$whMaxConn}</b>\n";
        $t .= "⚠️ Error Terakhir: <i>" . htmlspecialchars($whLastErr) . "</i>\n";
        if ($whLastDate !== 'N/A') $t .= "📅 Waktu Error: <b>{$whLastDate}</b>\n";
        $t .= "🗄️ DB Size: <b>{$dbSizeStr}</b>  |  Log: <b>{$logSizeStr}</b>\n";
        $t .= "\n";

        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🕐 <b>WAKTU</b>\n";
        $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $t .= "🌍 Timezone: <b>{$timezone}</b>\n";
        $t .= "📅 Waktu Sekarang: <b>{$nowLocal}</b>\n";

        return $t;
    }

    /**
     * Buat progress bar teks sederhana dari persentase.
     */
    private function sysBar(int $pct): string {
        $filled = (int)round($pct / 10);
        $empty  = 10 - $filled;
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }

    private function txtSettings(array $s, int $chatId): string {
        $on  = fn($v) => $v ? '✅' : '❌';
        $wl  = json_decode($s['antilink_whitelist'] ?? '[]', true) ?: [];
        $bw  = json_decode($s['banned_words'] ?? '[]', true) ?: [];
        $lch = $s['log_channel'] ? htmlspecialchars($s['log_channel']) : $this->t('settings.log_not_set');
        $langName = $this->t('lang.name_' . ($s['language'] ?? 'id'));
        return $this->t('settings.title') . "\n"
            . ($s['title'] ? "\n📌 <b>" . htmlspecialchars($s['title']) . "</b>\n" : '')
            . $this->t('settings.section_member')
            . $on($s['welcome_enabled'])    . " Welcome | "
            . $on($s['goodbye_enabled'])    . " Goodbye\n"
            . $on($s['captcha_enabled'])    . " Captcha (timeout: {$s['captcha_timeout']}s)\n"
            . $on($s['anti_bot'])           . " Anti-Bot | "
            . $on($s['anti_raid'])          . " Anti-Raid ({$s['raid_limit']}/" . $this->t('settings.per_min') . ")\n"
            . $this->t('settings.section_sec')
            . $on($s['antiflood_enabled'])  . " Anti-Flood ({$s['antiflood_limit']}/" . $this->t('settings.per_min') . " → {$s['antiflood_action']})\n"
            . $on($s['antilink_enabled'])   . " Anti-Link (whitelist: " . count($wl) . ")\n"
            . $on($s['antispam_enabled'])   . " Anti-Spam | "
            . $on($s['anti_forward'])       . " Anti-Forward\n"
            . $on($s['word_filter_enabled']). " Filter (" . count($bw) . ")\n"
            . $this->t('settings.section_ctrl')
            . $on($s['clean_service'])       . " Clean Svc | "
            . $on($s['ro_mode'])             . " Readonly\n"
            . "⚠️ Warn: <b>{$s['warn_limit']}</b> → <b>{$s['warn_action']}</b>\n"
            . $on($s['violation_enabled'])   . " " . $this->t('settings.violation_label') . ": <b>{$s['violation_limit']}</b> → <b>{$s['violation_action']}</b>\n"
            . $on($s['votekick_enabled'])    . " VoteKick: <b>" . ($s['votekick_threshold'] ?? 5) . "</b>v/<b>" . ($s['votekick_duration'] ?? 5) . "</b>m\n"
            . $on($s['ai_enabled'])            . " AI (" . htmlspecialchars(AiConfig::getChatModelInfo(AiService::getActiveChatModel($this->db))['short']) . ")\n"
            . $on($s['rules_btn_enabled'] ?? 0) . " Rules Btn\n"
            . $on($s['sang_mata_enabled'] ?? 0) . " " . $this->t('sang_mata.label') . "\n"
            . "🌐 " . $this->t('settings.log_channel', ['channel' => $lch]) . "\n"
            . "🌐 Lang: <b>$langName</b>";
    }

    private function txtWelcomeSettings(array $s): string {
        $on   = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $btns = json_decode($s['welcome_buttons'] ?? '[]', true) ?: [];
        $btnInfo = empty($btns)
            ? $this->t('settings.welcome_btns_none')
            : $this->t('settings.welcome_btns_count', ['n' => count($btns)]);
        return $this->t('settings.welcome_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['welcome_enabled'])]) . "\n\n"
            . $this->t('settings.welcome_msg', ['message' => htmlspecialchars($s['welcome_message'])]) . "\n\n"
            . $this->t('settings.welcome_vars') . "\n\n"
            . $this->t('settings.welcome_btns', ['info' => $btnInfo]);
    }

    private function txtGoodbyeSettings(array $s): string {
        $on = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        return $this->t('settings.goodbye_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['goodbye_enabled'])]) . "\n\n"
            . $this->t('settings.goodbye_msg', ['message' => htmlspecialchars($s['goodbye_message'])]) . "\n\n"
            . $this->t('settings.welcome_vars');
    }

    private function txtCaptchaSettings(array $s): string {
        $on      = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $type    = $s['captcha_type'] ?? 'math';
        $typeLabel = $type === 'hcaptcha' ? '🌐 hCaptcha (web)' : '🔢 Matematika';
        return $this->t('settings.captcha_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['captcha_enabled'])]) . "\n"
            . $this->t('settings.captcha_type', ['type' => $typeLabel]) . "\n"
            . $this->t('settings.captcha_timeout', ['timeout' => $s['captcha_timeout']]) . "\n\n"
            . $this->t('settings.captcha_info');
    }

    private function txtFloodSettings(array $s): string {
        $on = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        return $this->t('settings.flood_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['antiflood_enabled'])]) . "\n"
            . $this->t('settings.flood_limit', ['limit' => $s['antiflood_limit']]) . "\n"
            . $this->t('settings.flood_action', ['action' => $s['antiflood_action']]) . "\n"
            . $this->t('settings.flood_duration', ['duration' => $s['antiflood_duration']]);
    }

    private function txtAntilinkSettings(array $s): string {
        $on  = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $wl  = json_decode($s['antilink_whitelist'] ?? '[]', true) ?: [];
        return $this->t('settings.antilink_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['antilink_enabled'])]) . "\n\n"
            . $this->t('settings.antilink_wl', ['count' => count($wl)]) . "\n"
            . (empty($wl) ? $this->t('settings.antilink_wl_empty') : implode(', ', $wl));
    }

    private function txtWordFilterSettings(array $s): string {
        $on = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $bw  = json_decode($s['banned_words'] ?? '[]', true) ?: [];
        return $this->t('settings.wordfilter_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['word_filter_enabled'])]) . "\n\n"
            . $this->t('settings.wordfilter_bw', ['count' => count($bw)]) . "\n"
            . (empty($bw) ? $this->t('settings.wordfilter_empty') : htmlspecialchars(implode(', ', $bw)));
    }

    private function txtWarnSettings(array $s): string {
        return $this->t('settings.warn_title') . "\n\n"
            . $this->t('settings.warn_limit', ['limit' => $s['warn_limit']]) . "\n"
            . $this->t('settings.warn_action', ['action' => $s['warn_action']]);
    }

    private function txtViolationSettings(array $s): string {
        $on = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        return $this->t('settings.vp_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['violation_enabled'])]) . "\n"
            . $this->t('settings.vp_limit', ['limit' => $s['violation_limit']]) . "\n"
            . $this->t('settings.vp_action', ['action' => $s['violation_action']]) . "\n\n"
            . $this->t('settings.vp_howto', ['action' => $s['violation_action']]);
    }

    private function txtLocks(array $s): string {
        $on = fn($v) => $v ? '🔒' : '🔓';
        return $this->t('settings.locks_title') . "\n\n"
            . $on($s['lock_sticker']) . " Sticker\n"
            . $on($s['lock_gif'])     . " GIF / Animasi\n"
            . $on($s['lock_media'])   . " Media (foto/video)\n"
            . $on($s['lock_voice'])   . " Voice &amp; Video Note\n"
            . $on($s['lock_game'])    . " Game\n"
            . $on($s['lock_poll'])    . " Poll\n"
            . $on($s['anti_forward']) . " Forward";
    }

    // ================================================================
    //  KEYBOARD BUILDERS
    // ================================================================

    private function kbMain(): array {
        $kb = [
            [['text' => $this->t('start.btn_help'), 'callback_data' => 'menu_help'],
             ['text' => $this->t('start.btn_features'), 'callback_data' => 'menu_features']],
            [['text' => $this->t('start.btn_about'), 'callback_data' => 'menu_about']],
            [['text' => $this->t('start.btn_mygroups'), 'callback_data' => 'menu_mygroups']],
            [['text' => $this->t('start.btn_language'), 'callback_data' => 'lang_panel']],
        ];
        if (self::isSA($this->userId)) $kb[] = [['text' => $this->t('start.btn_sa'), 'callback_data' => 'sa_panel']];
        return ['inline_keyboard' => $kb];
    }

    private function kbSA(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_cat_broadcast'), 'callback_data' => 'sa_cat_broadcast'],
             ['text' => $this->t('sa.kb_cat_stats'),     'callback_data' => 'sa_cat_stats']],
            [['text' => $this->t('sa.kb_cat_users'),     'callback_data' => 'sa_cat_users'],
             ['text' => $this->t('sa.kb_verif'),         'callback_data' => 'sa_verif']],
            [['text' => $this->t('sa.kb_cat_settings'),  'callback_data' => 'sa_cat_settings'],
             ['text' => $this->t('sa.kb_cat_services'),  'callback_data' => 'sa_cat_services']],
            [['text' => $this->t('sa.kb_cat_system'),    'callback_data' => 'sa_cat_system']],
            [['text' => $this->t('kb.refresh'),          'callback_data' => 'sa_panel'],
             ['text' => $this->t('kb.back'),             'callback_data' => 'menu_main']],
        ]];
    }

    private function kbSACatBroadcast(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_broadcast'), 'callback_data' => 'sa_broadcast_prompt'],
             ['text' => $this->t('sa.kb_schedule'),  'callback_data' => 'sa_schedule_prompt']],
            [['text' => '📋 Antrian Jadwal',                                                  'callback_data' => 'sa_schedule_list'],
             ['text' => $this->t('sa.kb_history'),   'callback_data' => 'sa_broadcast_history']],
            [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbSACatStats(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_stats'),  'callback_data' => 'sa_stats'],
             ['text' => $this->t('sa.kb_groups'), 'callback_data' => 'sa_groups']],
            [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbSACatUsers(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_gban'),   'callback_data' => 'sa_gbanlist'],
             ['text' => $this->t('sa.kb_sa'),     'callback_data' => 'sa_salist']],
            [['text' => $this->t('sa.kb_lookup'), 'callback_data' => 'sa_lookup_prompt']],
            [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbSACatSettings(): array {
        $maint    = $this->db->getBotSetting('maintenance_mode', '0');
        $maintBtn = $maint === '1' ? $this->t('sa.kb_maint_on') : $this->t('sa.kb_maint_off');
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_ai_settings'),  'callback_data' => 'sa_ai_settings']],
            [['text' => $this->t('sa.kb_img_settings'), 'callback_data' => 'sa_img_settings']],
            [['text' => $maintBtn,                       'callback_data' => 'sa_maintenance']],
            [['text' => $this->t('sa.cat_back_btn'),    'callback_data' => 'sa_panel']],
        ]];
    }

    private function txtSaAiSettings(): string {
        $chatId   = AiService::getActiveChatModel($this->db);
        $imgId    = AiService::getActiveImageModel($this->db);
        $chatInfo = AiConfig::getChatModelInfo($chatId);
        $imgInfo  = AiConfig::getImageModelInfo($imgId);

        return $this->t('sa.ai_settings_title', [
            'chat_label'      => htmlspecialchars($chatInfo['label']),
            'chat_id'         => htmlspecialchars($chatId),
            'image_label'     => htmlspecialchars($imgInfo['label']),
            'image_id'        => htmlspecialchars($imgId),
            'gemini_status'   => AiConfig::isGeminiKeyConfigured() ? $this->t('sa.ai_key_ok') : $this->t('sa.ai_key_missing'),
            'deepseek_status' => AiConfig::isDeepSeekKeyConfigured() ? $this->t('sa.ai_key_ok') : $this->t('sa.ai_key_missing'),
        ]);
    }

    private function kbSaAiSettings(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.ai_kb_chat'),       'callback_data' => 'sa_ai_chat_menu']],
            [['text' => $this->t('sa.ai_kb_image'),      'callback_data' => 'sa_ai_image_menu']],
            [['text' => $this->t('sa.kb_img_settings'),  'callback_data' => 'sa_img_settings']],
            [['text' => $this->t('sa.cat_back_btn'),     'callback_data' => 'sa_cat_settings']],
        ]];
    }

    private function txtSaAiChatMenu(): string {
        $activeId = AiService::getActiveChatModel($this->db);
        $info     = AiConfig::getChatModelInfo($activeId);
        $txt      = $this->t('sa.ai_chat_menu_title', ['current' => htmlspecialchars($info['label'])]) . "\n\n";
        foreach (AiConfig::CHAT_MODELS as $id => $m) {
            $mark = ($id === $activeId) ? '✅ ' : '▫️ ';
            $txt .= "{$mark}<b>" . htmlspecialchars($m['label']) . "</b> (<code>{$id}</code>)\n   <i>" . htmlspecialchars($m['desc']) . "</i>\n";
        }
        return $txt;
    }

    private function kbSaAiChatMenu(): array {
        $activeId = AiService::getActiveChatModel($this->db);
        $rows     = [];
        foreach (AiConfig::CHAT_MODELS as $id => $m) {
            $prefix = ($id === $activeId) ? '✅ ' : '';
            $rows[] = [['text' => $prefix . $m['label'], 'callback_data' => "sa_set_ai_chat:$id"]];
        }
        $rows[] = [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_ai_settings']];
        return ['inline_keyboard' => $rows];
    }

    private function txtSaAiImageMenu(): string {
        $activeId = AiService::getActiveImageModel($this->db);
        $info     = AiConfig::getImageModelInfo($activeId);
        $txt      = $this->t('sa.ai_image_menu_title', ['current' => htmlspecialchars($info['label'])]) . "\n\n";
        foreach (AiConfig::IMAGE_MODELS as $id => $m) {
            $mark = ($id === $activeId) ? '✅ ' : '▫️ ';
            $txt .= "{$mark}<b>" . htmlspecialchars($m['label']) . "</b> (<code>{$id}</code>)\n   <i>" . htmlspecialchars($m['desc']) . "</i>\n";
        }
        return $txt;
    }

    private function kbSaAiImageMenu(): array {
        $activeId = AiService::getActiveImageModel($this->db);
        $rows     = [];
        foreach (AiConfig::IMAGE_MODELS as $id => $m) {
            $prefix = ($id === $activeId) ? '✅ ' : '';
            $rows[] = [['text' => $prefix . $m['label'], 'callback_data' => "sa_set_ai_image:$id"]];
        }
        $rows[] = [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_ai_settings']];
        return ['inline_keyboard' => $rows];
    }

    private function kbSACatServices(): array {
        return ['inline_keyboard' => [
            [['text' => '📧 Email',    'callback_data' => 'sa_email'],
             ['text' => '📬 TempMail', 'callback_data' => 'sa_tempmail']],
            [['text' => '🖥️ WHM',     'callback_data' => 'sa_whm'],
             ['text' => '⚙️ cPanel',  'callback_data' => 'sa_cpanel']],
            [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbSACatSystem(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('sa.kb_logs'),    'callback_data' => 'sa_logs'],
             ['text' => $this->t('sa.kb_backup'),  'callback_data' => 'sa_backup']],
            [['text' => $this->t('sa.kb_sysinfo'), 'callback_data' => 'sa_sysinfo'],
             ['text' => $this->t('sa.kb_ping'),    'callback_data' => 'sa_ping']],
            [['text' => $this->t('sa.cat_back_btn'), 'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbSettings(array $s, int $cid, bool $fromPM = false): array {
        $on  = fn($v) => $v ? '✅' : '❌';
        $kb  = [
            [['text' => $on($s['welcome_enabled']) . ' Welcome',       'callback_data' => "settings_welcome:$cid"],
             ['text' => $on($s['goodbye_enabled']) . ' Goodbye',       'callback_data' => "settings_goodbye:$cid"]],
            [['text' => $on($s['captcha_enabled']) . ' Captcha',       'callback_data' => "settings_captcha:$cid"],
             ['text' => $on($s['anti_raid']) . ' Anti-Raid',           'callback_data' => "settings_antiraid:$cid"]],
            [['text' => $on($s['antiflood_enabled']) . ' Anti-Flood',  'callback_data' => "settings_flood:$cid"],
             ['text' => $on($s['antilink_enabled']) . ' Anti-Link',    'callback_data' => "settings_antilink:$cid"]],
            [['text' => $on($s['antispam_enabled']) . ' Anti-Spam',    'callback_data' => "toggle:antispam_enabled:$cid"],
             ['text' => $on($s['anti_forward']) . ' Anti-Forward',     'callback_data' => "toggle:anti_forward:$cid"]],
            [['text' => $on($s['anti_bot']) . ' Anti-Bot',             'callback_data' => "toggle:anti_bot:$cid"],
             ['text' => $on($s['word_filter_enabled']) . ' ' . $this->t('kb.filter'), 'callback_data' => "settings_wordfilter:$cid"]],
            [['text' => $on($s['clean_service']) . ' ' . $this->t('kb.clean_svc'),    'callback_data' => "toggle:clean_service:$cid"],
             ['text' => $on($s['ro_mode']) . ' Readonly',              'callback_data' => "toggle:ro_mode:$cid"]],
            [['text' => '🔒 Lock',                  'callback_data' => "settings_locks:$cid"],
             ['text' => '⚠️ Warn',                   'callback_data' => "settings_warn:$cid"]],
            [['text' => '🔴 ' . $this->t('kb.violation'), 'callback_data' => "settings_violation:$cid"],
             ['text' => '🗳️ Vote Kick',               'callback_data' => "settings_votekick:$cid"]],
            [['text' => '🤖 AI',                     'callback_data' => "settings_ai:$cid"],
             ['text' => '📋 ' . $this->t('kb.rules'), 'callback_data' => "settings_rules:$cid"]],
            [['text' => $on($s['sang_mata_enabled'] ?? 0) . ' ' . $this->t('sang_mata.label'), 'callback_data' => "toggle:sang_mata_enabled:$cid"]],
            [['text' => '📝 Log Ch.',                'callback_data' => "settings_logchannel:$cid"],
             ['text' => '🌐 ' . $this->t('kb.language'), 'callback_data' => "settings_setlang:$cid"]],
            [['text' => $this->t('kb.clearall'),     'callback_data' => "settings_clearall:$cid"]],
            [['text' => $this->t('kb.refresh'),      'callback_data' => "settings_menu:$cid"]],
        ];
        // Tombol kembali ke daftar grup — tampil saat settings dibuka dari PM
        if ($fromPM || $this->chatId > 0) {
            $kb[] = [
                ['text' => $this->t('pm_settings.btn_back_groups'), 'callback_data' => 'menu_mygroups'],
                ['text' => $this->t('kb.close'),                    'callback_data' => 'close_settings'],
            ];
        } else {
            $kb[] = [['text' => $this->t('kb.close'), 'callback_data' => 'close_settings']];
        }
        return ['inline_keyboard' => $kb];
    }

    private function kbWelcomeSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on   = fn($v) => $v ? '✅ ON' : '❌ OFF';
        $btns = json_decode($s['welcome_buttons'] ?? '[]', true) ?: [];
        return ['inline_keyboard' => [
            [['text' => $on($s['welcome_enabled']) . ' Welcome',        'callback_data' => "toggle_welcome:$cid"]],
            [['text' => '✏️ ' . $this->t('kb.edit_msg_welcome'),         'callback_data' => "set_welcome_prompt:$cid"]],
            [['text' => '🔗 ' . $this->t('kb.add_btn', ['n' => count($btns)]), 'callback_data' => "set_welcome_btn_prompt:$cid"],
             ['text' => '🗑️ ' . $this->t('kb.del_btn'),                  'callback_data' => "clear_welcome_buttons:$cid"]],
            [['text' => $this->t('kb.back_settings'),                   'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbGoodbyeSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => $on($s['goodbye_enabled']) . ' Goodbye',        'callback_data' => "toggle_goodbye:$cid"]],
            [['text' => '✏️ ' . $this->t('kb.edit_msg_goodbye'),          'callback_data' => "set_goodbye_prompt:$cid"]],
            [['text' => $this->t('kb.back_settings'),                   'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbCaptchaSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on   = fn($v) => $v ? '✅ ON' : '❌ OFF';
        $type = $s['captcha_type'] ?? 'math';
        $typeBtn = $type === 'hcaptcha' ? '🌐 hCaptcha (web)' : '🔢 Matematika';
        return ['inline_keyboard' => [
            [['text' => $on($s['captcha_enabled']) . ' Captcha',        'callback_data' => "toggle_captcha:$cid"]],
            [['text' => "🔄 Metode: $typeBtn",                          'callback_data' => "toggle_captcha_type:$cid"]],
            [['text' => "⏱️ Timeout: {$s['captcha_timeout']}s",          'callback_data' => "set_captcha_timeout_prompt:$cid"]],
            [['text' => $this->t('kb.back_settings'),                   'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbAntilinkSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on  = fn($v) => $v ? '✅ ON' : '❌ OFF';
        $wl  = json_decode($s['antilink_whitelist'] ?? '[]', true) ?: [];
        return ['inline_keyboard' => [
            [['text' => $on($s['antilink_enabled']) . ' Anti-Link',     'callback_data' => "toggle_antilink:$cid"]],
            [['text' => '🔗 ' . $this->t('kb.set_wl', ['n' => count($wl)]), 'callback_data' => "set_antilink_wl_prompt:$cid"],
             ['text' => '🗑️ ' . $this->t('kb.clear_wl'),                'callback_data' => "clear_antilink_wl:$cid"]],
            [['text' => $this->t('kb.back_settings'),                   'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbWordFilterSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        $bw  = json_decode($s['banned_words'] ?? '[]', true) ?: [];
        return ['inline_keyboard' => [
            [['text' => $on($s['word_filter_enabled']) . ' ' . $this->t('kb.filter'), 'callback_data' => "toggle_wordfilter:$cid"]],
            [['text' => '➕ ' . $this->t('kb.add_word', ['n' => count($bw)]),           'callback_data' => "set_banword_prompt:$cid"],
             ['text' => '🗑️ ' . $this->t('kb.del_all'),                                 'callback_data' => "clear_banwords:$cid"]],
            [['text' => $this->t('kb.back_settings'),                                  'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbRulesSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => '✏️ ' . $this->t('kb.set_rules'),  'callback_data' => "set_rules_prompt:$cid"],
             ['text' => '🗑️ ' . $this->t('kb.del_rules'),  'callback_data' => "clear_rules:$cid"]],
            [['text' => $on($s['rules_btn_enabled'] ?? 0) . ' ' . $this->t('kb.rules_agree_btn'), 'callback_data' => "toggle_rules_btn:$cid"]],
            [['text' => $on($s['require_verification'] ?? 0) . ' 🔐 ' . $this->t('kb.require_verification'), 'callback_data' => "toggle_require_verification:$cid"]],
            [['text' => $this->t('kb.back_settings'),       'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbLogChannelSettings(int $cid, int $backMsgId = 0): array {
        return ['inline_keyboard' => [
            [['text' => '📝 ' . $this->t('kb.set_log_ch'), 'callback_data' => "set_logchannel_prompt:$cid"],
             ['text' => '🗑️ ' . $this->t('kb.delete'),     'callback_data' => "clear_logchannel:$cid"]],
            [['text' => $this->t('kb.back_settings'),       'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbLangPanel(int $cid = 0, bool $showBack = true): array {
        $rows = [
            [['text' => '🇮🇩 Bahasa Indonesia', 'callback_data' => ($cid ? "setlang_group:id:$cid" : 'setlang_user:id')]],
            [['text' => '🇬🇧 English',           'callback_data' => ($cid ? "setlang_group:en:$cid" : 'setlang_user:en')]],
        ];
        if ($showBack) {
            $backCb = $cid ? "settings_menu:$cid" : 'menu_main';
            $rows[] = [['text' => $this->t('kb.back'), 'callback_data' => $backCb]];
        }
        return ['inline_keyboard' => $rows];
    }

    private function kbLocks(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '🔒' : '🔓';
        return ['inline_keyboard' => [
            [['text' => $on($s['lock_sticker']) . ' Sticker', 'callback_data' => "toggle_lock:lock_sticker:$cid"],
             ['text' => $on($s['lock_gif'])     . ' GIF',     'callback_data' => "toggle_lock:lock_gif:$cid"]],
            [['text' => $on($s['lock_media'])   . ' Media',   'callback_data' => "toggle_lock:lock_media:$cid"],
             ['text' => $on($s['lock_voice'])   . ' Voice',   'callback_data' => "toggle_lock:lock_voice:$cid"]],
            [['text' => $on($s['lock_game'])    . ' Game',    'callback_data' => "toggle_lock:lock_game:$cid"],
             ['text' => $on($s['lock_poll'])    . ' Poll',    'callback_data' => "toggle_lock:lock_poll:$cid"]],
            [['text' => $on($s['anti_forward']) . ' Forward', 'callback_data' => "toggle_lock:anti_forward:$cid"]],
            [['text' => $this->t('kb.back_settings'), 'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbFlood(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => $on($s['antiflood_enabled']) . ' Anti-Flood',       'callback_data' => "toggle_flood:$cid"]],
            [['text' => "📊 Limit: {$s['antiflood_limit']}/" . $this->t('settings.per_min'), 'callback_data' => "flood_limit:$cid"],
             ['text' => "⚡ " . $this->t('kb.action') . ": {$s['antiflood_action']}", 'callback_data' => "flood_action:$cid"]],
            [['text' => "⏱️ Mute: {$s['antiflood_duration']}s",             'callback_data' => "flood_duration_prompt:$cid"]],
            [['text' => $this->t('kb.back_settings'),                       'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbWarn(array $s, int $cid, int $backMsgId = 0): array {
        return ['inline_keyboard' => [
            [['text' => "⚠️ Limit: {$s['warn_limit']}x",                   'callback_data' => "warn_limit:$cid"],
             ['text' => "⚡ " . $this->t('kb.action') . ": {$s['warn_action']}", 'callback_data' => "warn_action:$cid"]],
            [['text' => $this->t('kb.back_settings'),                       'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbViolationSettings(array $s, int $cid): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => $on($s['violation_enabled']) . ' ' . $this->t('kb.violation'), 'callback_data' => "toggle_violation:$cid"]],
            [['text' => "🔢 " . $this->t('kb.limit') . ": {$s['violation_limit']}",    'callback_data' => "violation_limit:$cid"],
             ['text' => "⚡ " . $this->t('kb.action') . ": {$s['violation_action']}",  'callback_data' => "violation_action:$cid"]],
            [['text' => $this->t('kb.back_settings'),                                  'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function buildVotekickMsg(array $session, int $minLeft): string {
        $tnam   = htmlspecialchars($session['target_name']);
        $reason = htmlspecialchars($session['reason']);
        $yes    = (int)$session['yes_count'];
        $no     = (int)$session['no_count'];
        $thresh = (int)$session['threshold'];
        $total  = $yes + $no;
        $bar    = '';
        if ($total > 0) {
            $filled = (int)round(($yes / $thresh) * 10);
            $filled = min(10, max(0, $filled));
            $bar    = str_repeat('🟩', $filled) . str_repeat('⬜', 10 - $filled) . " $yes/$thresh\n";
        }
        return $this->t('vk.msg', [
            'target'  => $tnam,
            'reason'  => $reason,
            'bar'     => $bar,
            'yes'     => $yes,
            'no'      => $no,
            'thresh'  => $thresh,
            'min'     => $minLeft,
        ]);
    }

    private function buildVotekickKb(array $session): array {
        $id  = $session['id'];
        $yes = (int)$session['yes_count'];
        $no  = (int)$session['no_count'];
        return ['inline_keyboard' => [
            [
                ['text' => $this->t('vk.btn_yes', ['yes' => $yes]), 'callback_data' => "vk_yes:$id"],
                ['text' => $this->t('vk.btn_no',  ['no'  => $no]),  'callback_data' => "vk_no:$id"],
            ],
            [['text' => $this->t('vk.btn_cancel'), 'callback_data' => "vk_cancel:$id"]],
        ]];
    }

    private function txtVotekickSettings(array $s): string {
        $on       = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $dur      = (int)($s['votekick_duration'] ?? 5);
        $mode     = (int)($s['votekick_mode'] ?? 0);
        $thr      = (int)($s['votekick_threshold'] ?? 5);
        $pct      = (int)($s['votekick_percent'] ?? 10);
        $adminOnly= (int)($s['votekick_admin_only'] ?? 0);
        $cooldown = (int)($s['votekick_cooldown'] ?? 0);

        $modeLbl    = $mode === 1 ? $this->t('settings.vk_mode_pct_label') : $this->t('settings.vk_mode_abs_label');
        $threshLine = $mode === 1
            ? $this->t('settings.vk_threshold_pct', ['n' => $pct])
            : $this->t('settings.vk_threshold', ['n' => $thr]);
        $adminLine  = $adminOnly ? $this->t('settings.vk_admin_only_on') : $this->t('settings.vk_admin_only_off');
        $cdLine     = $cooldown > 0 ? $this->t('settings.vk_cooldown', ['n' => $cooldown]) : $this->t('settings.vk_cooldown_off');

        return $this->t('settings.vk_title') . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['votekick_enabled'])]) . "\n"
            . $this->t('settings.vk_mode', ['mode' => $modeLbl]) . "\n"
            . $threshLine . "\n"
            . $this->t('settings.vk_duration', ['n' => $dur]) . "\n"
            . $adminLine . "\n"
            . $cdLine . "\n\n"
            . $this->t('settings.vk_howto');
    }

    private function kbVotekickSettings(array $s, int $cid): array {
        $on    = fn($v) => $v ? '✅ ON' : '❌ OFF';
        $dur   = (int)($s['votekick_duration'] ?? 5);
        $mode  = (int)($s['votekick_mode'] ?? 0);
        $thr   = (int)($s['votekick_threshold'] ?? 5);
        $pct   = (int)($s['votekick_percent'] ?? 10);
        $ao    = (int)($s['votekick_admin_only'] ?? 0);
        $cd    = (int)($s['votekick_cooldown'] ?? 0);

        $threshBtn = $mode === 1
            ? ['text' => "📊 {$pct}% member", 'callback_data' => "votekick_percent:$cid"]
            : ['text' => "🗳️ {$thr} " . $this->t('kb.votes'), 'callback_data' => "votekick_threshold:$cid"];
        $cdTxt  = $cd > 0 ? "⏳ CD: {$cd}m" : "⏳ CD: Off";
        $aoTxt  = $ao ? '🔑 Admin Only' : '👤 All Members';
        $modeTxt= $mode === 1 ? '📊 Mode: %' : '🔢 Mode: #';

        return ['inline_keyboard' => [
            [['text' => $on($s['votekick_enabled']) . ' Vote Kick', 'callback_data' => "toggle_votekick:$cid"]],
            [['text' => $modeTxt, 'callback_data' => "votekick_mode:$cid"]],
            [$threshBtn,
             ['text' => $this->t('kb.duration_min', ['n' => $dur]), 'callback_data' => "votekick_duration:$cid"]],
            [['text' => $aoTxt, 'callback_data' => "toggle_vk_admin_only:$cid"],
             ['text' => $cdTxt, 'callback_data' => "votekick_cooldown:$cid"]],
            [['text' => $this->t('kb.back_settings'), 'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function txtAiSettings(array $s): string {
        $on        = fn($v) => $v ? $this->t('settings.status_active') : $this->t('settings.status_inactive');
        $chatInfo  = AiConfig::getChatModelInfo(AiService::getActiveChatModel($this->db));
        $imageInfo = AiConfig::getImageModelInfo(AiService::getActiveImageModel($this->db));
        return $this->t('settings.ai_title', ['provider' => htmlspecialchars($chatInfo['short'])]) . "\n\n"
            . $this->t('settings.welcome_status', ['status' => $on($s['ai_enabled'])]) . "\n\n"
            . $this->t('settings.ai_howto', [
                'chat_model'  => htmlspecialchars($chatInfo['label']),
                'image_model' => htmlspecialchars($imageInfo['label']),
            ]);
    }

    private function kbAiSettings(array $s, int $cid): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => $on($s['ai_enabled']) . ' AI', 'callback_data' => "toggle_ai:$cid"]],
            [['text' => $this->t('kb.back_settings'),  'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    private function kbAntiraidSettings(array $s, int $cid, int $backMsgId = 0): array {
        $on = fn($v) => $v ? '✅ ON' : '❌ OFF';
        return ['inline_keyboard' => [
            [['text' => $on($s['anti_raid']) . ' Anti-Raid',               'callback_data' => "toggle_antiraid:$cid"]],
            [['text' => "👥 " . $this->t('kb.limit') . ": {$s['raid_limit']}/m", 'callback_data' => "raid_limit:$cid"]],
            [['text' => $this->t('kb.back_settings'),                      'callback_data' => "settings_menu:$cid"]],
        ]];
    }

    // ================================================================
    //  EMAIL PANEL TEXT & KEYBOARD
    // ================================================================

    private function txtWhmPanel(): string {
        $cfg = WhmManager::getRuntimeConfig();
        $hostDisplay = $cfg['whm_host'] ?: '❌ Belum dikonfigurasi';
        $authMethod  = $cfg['whm_token'] ? '🔑 API Token' : ($cfg['whm_pass'] ? '🔒 Password' : '❌ Tidak ada');
        return "🖥️ <b>Panel WHM</b>\n\n"
             . "🌐 <b>Server:</b> <code>" . htmlspecialchars($hostDisplay) . ":{$cfg['whm_port']}</code>\n"
             . "👤 <b>User:</b> <code>" . ($cfg['whm_user'] ?: '❌ Belum diisi') . "</code>\n"
             . "🔐 <b>Auth:</b> {$authMethod}\n\n"
             . "📋 <b>Fitur WHM:</b>\n"
             . "• Tes koneksi server\n"
             . "• Info &amp; load server\n"
             . "• Daftar akun cPanel\n"
             . "• Kelola FTP via WHM\n\n"
             . "🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
    }

    private function kbWhmPanel(): array {
        return ['inline_keyboard' => [
            [['text' => '🔌 Tes Koneksi',      'callback_data' => 'sa_whm_test'],
             ['text' => '🖥️ Info Server',       'callback_data' => 'sa_whm_serverinfo']],
            [['text' => '📊 Dashboard',         'callback_data' => 'sa_whm_dashboard'],
             ['text' => '👥 Daftar Akun',       'callback_data' => 'sa_whm_accounts']],
            [['text' => '⚙️ Kelola Akun',       'callback_data' => 'sa_whm_acct'],
             ['text' => '📂 Kelola FTP',        'callback_data' => 'sa_whm_ftp']],
            [['text' => '🌐 Manajemen DNS',     'callback_data' => 'sa_whm_dns'],
             ['text' => '📈 Bandwidth',         'callback_data' => 'sa_whm_bandwidth']],
            [['text' => '🔄 Restart Layanan',   'callback_data' => 'sa_whm_restart_services'],
             ['text' => '💾 Info Disk',         'callback_data' => 'sa_whm_diskinfo']],
            [['text' => '📦 Package/Plan',      'callback_data' => 'sa_whm_pkg'],
             ['text' => '🌐 Daftar IP',         'callback_data' => 'sa_whm_ips']],
            [['text' => '👔 Reseller',          'callback_data' => 'sa_whm_reseller'],
             ['text' => '⚙️ Settings WHM',      'callback_data' => 'sa_whm_settings_view']],
            [['text' => '🔙 Superadmin',        'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbWhmAcct(): array {
        return ['inline_keyboard' => [
            [['text' => '⛔ Suspend Akun',      'callback_data' => 'sa_whm_suspend_prompt'],
             ['text' => '✅ Unsuspend Akun',    'callback_data' => 'sa_whm_unsuspend_prompt']],
            [['text' => '➕ Buat Akun Baru',   'callback_data' => 'sa_whm_create_acct_prompt'],
             ['text' => '🔑 Ubah Password',    'callback_data' => 'sa_whm_chpass_prompt']],
            [['text' => '✏️ Modifikasi Akun',   'callback_data' => 'sa_whm_modify_acct_prompt'],
             ['text' => '🗑️ Hapus Akun',        'callback_data' => 'sa_whm_remove_acct_prompt']],
            [['text' => '🔙 Panel WHM',         'callback_data' => 'sa_whm']],
        ]];
    }

    private function kbWhmPkg(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Package',    'callback_data' => 'sa_whm_pkg_list']],
            [['text' => '➕ Buat Package',      'callback_data' => 'sa_whm_pkg_add_prompt'],
             ['text' => '🗑️ Hapus Package',     'callback_data' => 'sa_whm_pkg_del_prompt']],
            [['text' => '🔙 Panel WHM',         'callback_data' => 'sa_whm']],
        ]];
    }

    private function kbWhmReseller(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Reseller',       'callback_data' => 'sa_whm_reseller_list']],
            [['text' => '👔 Jadikan Reseller',      'callback_data' => 'sa_whm_make_reseller_prompt'],
             ['text' => '🚫 Hapus Reseller',        'callback_data' => 'sa_whm_unsetup_reseller_prompt']],
            [['text' => '🔙 Panel WHM',             'callback_data' => 'sa_whm']],
        ]];
    }

    private function kbWhmFtp(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar FTP',   'callback_data' => 'sa_whm_ftp_list:'],
             ['text' => '➕ Buat FTP',     'callback_data' => 'sa_whm_ftp_create_prompt']],
            [['text' => '🔙 Panel WHM',    'callback_data' => 'sa_whm']],
        ]];
    }

    private function kbWhmDns(): array {
        return ['inline_keyboard' => [
            [['text' => '🗂️ Daftar Semua Zone', 'callback_data' => 'sa_whm_dns_zones']],
            [['text' => '📂 Lihat Zone',         'callback_data' => 'sa_whm_dns_zone_prompt'],
             ['text' => '➕ Tambah Record',      'callback_data' => 'sa_whm_dns_add_prompt']],
            [['text' => '🗑️ Hapus Record',        'callback_data' => 'sa_whm_dns_del_prompt'],
             ['text' => '♻️ Reset Zone',          'callback_data' => 'sa_whm_dns_reset_prompt:']],
            [['text' => '🔙 Panel WHM',           'callback_data' => 'sa_whm']],
        ]];
    }

    private function txtCpanelPanel(): string {
        $cfg = CpanelManager::getRuntimeConfig();
        $hostDisplay = $cfg['cpanel_host'] ?: '❌ Belum dikonfigurasi';
        $authOk = ($cfg['cpanel_user'] && $cfg['cpanel_pass']);
        return "⚙️ <b>Panel cPanel</b>\n\n"
             . "🌐 <b>Server:</b> <code>" . htmlspecialchars($hostDisplay) . ":{$cfg['cpanel_port']}</code>\n"
             . "👤 <b>User:</b> <code>" . ($cfg['cpanel_user'] ?: '❌ Belum diisi') . "</code>\n"
             . "🔒 <b>Password:</b> " . ($cfg['cpanel_pass'] ? '✅ Tersimpan' : '❌ Kosong') . "\n\n"
             . "📂 <b>Fitur cPanel:</b>\n"
             . "• Tes koneksi server\n"
             . "• Daftar / Buat / Hapus FTP\n"
             . "• Ubah password &amp; quota FTP\n\n"
             . "🕒 <i>" . date('d/m/Y H:i:s') . "</i>";
    }

    private function kbCpanelPanel(): array {
        return ['inline_keyboard' => [
            [['text' => '🔌 Tes Koneksi',      'callback_data' => 'sa_cpanel_test']],
            [['text' => '📂 Kelola FTP',        'callback_data' => 'sa_cpanel_ftp'],
             ['text' => '🗄️ MySQL',             'callback_data' => 'sa_cpanel_db']],
            [['text' => '🌐 Subdomain',         'callback_data' => 'sa_cpanel_subdomain'],
             ['text' => '📧 Forwarder',         'callback_data' => 'sa_cpanel_forwarder']],
            [['text' => '⏰ Cron Jobs',         'callback_data' => 'sa_cpanel_cron'],
             ['text' => '💾 Disk Usage',        'callback_data' => 'sa_cpanel_disk']],
            [['text' => '🔒 SSL/TLS',           'callback_data' => 'sa_cpanel_ssl']],
            [['text' => '⚙️ Settings cPanel',   'callback_data' => 'sa_cpanel_settings_view']],
            [['text' => '🔙 Superadmin',        'callback_data' => 'sa_panel']],
        ]];
    }

    private function kbCpanelDb(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Database',   'callback_data' => 'sa_cpanel_db_list']],
            [['text' => '➕ Buat Database',     'callback_data' => 'sa_cpanel_db_create_prompt'],
             ['text' => '🗑️ Hapus Database',    'callback_data' => 'sa_cpanel_db_delete_prompt']],
            [['text' => '👤 Buat User DB',      'callback_data' => 'sa_cpanel_db_user_create_prompt'],
             ['text' => '🔑 Grant Privileges',  'callback_data' => 'sa_cpanel_db_grant_prompt']],
            [['text' => '🔙 Panel cPanel',      'callback_data' => 'sa_cpanel']],
        ]];
    }

    private function kbCpanelSubdomain(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Subdomain',  'callback_data' => 'sa_cpanel_subdomain_list']],
            [['text' => '➕ Buat Subdomain',    'callback_data' => 'sa_cpanel_subdomain_create_prompt'],
             ['text' => '🗑️ Hapus Subdomain',   'callback_data' => 'sa_cpanel_subdomain_delete_prompt']],
            [['text' => '🔙 Panel cPanel',      'callback_data' => 'sa_cpanel']],
        ]];
    }

    private function kbCpanelForwarder(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Forwarder',  'callback_data' => 'sa_cpanel_forwarder_list']],
            [['text' => '➕ Tambah Forwarder',  'callback_data' => 'sa_cpanel_forwarder_create_prompt'],
             ['text' => '🗑️ Hapus Forwarder',   'callback_data' => 'sa_cpanel_forwarder_delete_prompt']],
            [['text' => '🔙 Panel cPanel',      'callback_data' => 'sa_cpanel']],
        ]];
    }

    private function kbCpanelCron(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar Cron',       'callback_data' => 'sa_cpanel_cron_list']],
            [['text' => '➕ Tambah Cron',       'callback_data' => 'sa_cpanel_cron_add_prompt'],
             ['text' => '🗑️ Hapus Cron',        'callback_data' => 'sa_cpanel_cron_delete_prompt']],
            [['text' => '🔙 Panel cPanel',      'callback_data' => 'sa_cpanel']],
        ]];
    }

    private function kbCpanelFtp(): array {
        return ['inline_keyboard' => [
            [['text' => '📋 Daftar FTP',    'callback_data' => 'sa_cpanel_ftp_list'],
             ['text' => '➕ Buat FTP',      'callback_data' => 'sa_cpanel_ftp_create_prompt']],
            [['text' => '🗑️ Hapus FTP',     'callback_data' => 'sa_cpanel_ftp_delete_prompt'],
             ['text' => '🔑 Ubah Password', 'callback_data' => 'sa_cpanel_ftp_passwd_prompt']],
            [['text' => '📦 Atur Quota',    'callback_data' => 'sa_cpanel_ftp_quota_prompt']],
            [['text' => '🔙 Panel cPanel',  'callback_data' => 'sa_cpanel']],
        ]];
    }

    private function txtEmailPanel(): string {
        $count = $this->db->countEmailAccounts();
        $cfg   = EmailManager::getRuntimeConfig();
        return $this->t('email.panel_header', [
            'count' => $count,
            'host'  => $cfg['mail_host'],
            'imap'  => $cfg['imap_port'],
            'smtp'  => $cfg['smtp_port'],
        ]);
    }

    private function kbEmailPanel(): array {
        return ['inline_keyboard' => [
            [['text' => $this->t('email.kb_accounts'), 'callback_data' => 'sa_email_accounts'],
             ['text' => $this->t('email.kb_create'),   'callback_data' => 'sa_email_create_prompt']],
            [['text' => $this->t('email.kb_sync'),     'callback_data' => 'sa_email_sync_cpanel'],
             ['text' => $this->t('email.kb_settings'), 'callback_data' => 'sa_email_server_settings']],
            [['text' => $this->t('email.kb_back_sa'),  'callback_data' => 'sa_panel']],
        ]];
    }

    /**
     * Tangani update my_chat_member — dipanggil saat status bot di grup berubah
     * (ditambahkan, dipromote, didemote, atau dikeluarkan).
     */
    private function handleMyChatMember(array $myChatMember): void {
        $chat      = $myChatMember['chat'];
        $chatId    = (int)$chat['id'];
        $chatType  = $chat['type'] ?? '';
        $oldStatus = $myChatMember['old_chat_member']['status'] ?? '';
        $newStatus = $myChatMember['new_chat_member']['status'] ?? '';

        // Hanya proses update di grup/supergroup
        if (!in_array($chatType, ['group', 'supergroup'])) return;

        // Bot baru bergabung, dipromote, atau izinnya diubah — cek ulang izin
        // Hindari duplikasi notifikasi saat bot baru bergabung (sudah ditangani handleJoin)
        $justJoined = ($oldStatus === 'left' || $oldStatus === 'kicked');
        if (!$justJoined && in_array($newStatus, ['administrator', 'member'])) {
            $this->syncGroupInfo($chat);
            $settings = $this->db->getGroupSettings($chatId);
            $this->loadLang($settings);
            // Data member sudah ada dalam update — pakai langsung tanpa API call ekstra
            $this->checkBotPermissions($chatId, $myChatMember['new_chat_member']);
        }
    }

    /**
     * Cek izin bot di grup dan kirim laporan ke grup.
     * Dipanggil saat bot bergabung, dipromote, atau admin menjalankan /checkperms.
     *
     * @param int   $chatId  ID grup
     * @param array $member  Data member dari API (opsional, untuk menghindari API call ekstra)
     */
    private function checkBotPermissions(int $chatId, array $member = []): void {
        if (empty($member)) {
            $botId  = self::api('getMe')['result']['id'] ?? 0;
            if (!$botId) return;
            $member = self::getChatMember($chatId, $botId);
        }

        $status = $member['status'] ?? '';

        // Bot bukan admin — semua fitur utama tidak akan berfungsi
        if ($status !== 'administrator') {
            self::send($chatId,
                "👋 <b>Halo! Saya sudah bergabung di grup ini.</b>\n\n" .
                "⚠️ Saya belum dijadikan admin. Harap jadikan saya admin dengan izin yang sesuai agar semua fitur dapat berfungsi.",
                ['parse_mode' => 'HTML']
            );
            return;
        }

        // Daftar izin penting beserta deskripsi fungsinya
        $required = [
            'can_delete_messages'  => '🗑 <b>Hapus Pesan</b> — /del, /purge, /clearall, bersih pesan captcha',
            'can_restrict_members' => '🔇 <b>Batasi Anggota</b> — mute, kick, ban, dan captcha',
            'can_pin_messages'     => '📌 <b>Sematkan Pesan</b> — /pin dan /unpin',
            'can_invite_users'     => '🔗 <b>Undang Pengguna</b> — pembuatan link undangan',
        ];

        $missing = [];
        foreach ($required as $perm => $desc) {
            if (empty($member[$perm])) {
                $missing[] = "• $desc";
            }
        }

        if (empty($missing)) {
            self::send($chatId,
                "✅ <b>Izin Bot Lengkap</b>\n\nSemua izin admin yang diperlukan sudah tersedia. Bot siap digunakan sepenuhnya!",
                ['parse_mode' => 'HTML']
            );
        } else {
            $list = implode("\n", $missing);
            self::send($chatId,
                "⚠️ <b>Izin Bot Kurang</b>\n\nIzin admin berikut belum diberikan:\n\n$list\n\n" .
                "Harap perbarui izin bot agar semua fitur dapat berfungsi dengan baik.",
                ['parse_mode' => 'HTML']
            );
        }
    }

    public static function log(string $msg, string $level = 'INFO'): void {
        $dir = dirname(LOG_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(LOG_PATH, "[" . date('Y-m-d H:i:s') . "] [$level] $msg\n", FILE_APPEND);
    }
}

// ================================================================
//  JALANKAN BOT
// ================================================================
try {
    (new TelegramBot($update))->handle();
} catch (Throwable $e) {
    TelegramBot::log("FATAL: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine(), 'ERROR');
}
