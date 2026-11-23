<?php
/**
 * ================================================================
 *  CRON JOB — Jalankan setiap 1 menit via cPanel Cron
 *  cPanel Cron Command:
 *    php /home/USERNAME/public_html/bot/cron.php >> /home/USERNAME/public_html/bot/data/cron.log 2>&1
 *
 *  Atau via URL (jika server mendukung):
 *    wget -q -O /dev/null "https://yourdomain.com/bot/cron.php?secret=CRON_SECRET"
 * ================================================================
 */



require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/handlers/tempmail.php';

$startTime = microtime(true);
$log = [];

// ================================================================
// LOCK MECHANISM — Cegah duplikasi run jika cron sebelumnya hang
// ================================================================
$lockFile = sys_get_temp_dir() . '/bot_cron.lock';
$lockFp   = @fopen($lockFile, 'c');
if (!$lockFp) {
    echo "[" . date('Y-m-d H:i:s') . "] CRON SKIP — Tidak bisa membuka lock file\n";
    exit(0);
}

// Jika lock file sudah > 5 menit, anggap run sebelumnya crash — paksa reset
if (file_exists($lockFile) && (time() - filemtime($lockFile)) > 300) {
    ftruncate($lockFp, 0);
    rewind($lockFp);
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Ada instance lain yang sedang berjalan, skip
    fclose($lockFp);
    echo "[" . date('Y-m-d H:i:s') . "] CRON SKIP — Instance lain sedang berjalan\n";
    exit(0);
}

// Update mtime agar stale detection akurat
touch($lockFile);

// Bersihkan lock saat script selesai (normal maupun crash)
register_shutdown_function(function() use ($lockFp, $lockFile) {
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    @unlink($lockFile);
});

// ================================================================
// Helper: panggil Telegram API
// ================================================================
function telegramApi(string $method, array $params = []): array {
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

$db = Database::getInstance();

function loadGroupLang(Database $db, int $chatId): void {
    $s = $db->getGroupSettings($chatId);
    Lang::load($s['language'] ?? 'id');
}

// ================================================================
// 1. KICK USER YANG CAPTCHA SUDAH EXPIRED
// ================================================================
$expiredCaptchas = $db->query(
    "SELECT * FROM captcha_pending WHERE expires_at < ?",
    [time()]
);

foreach ($expiredCaptchas as $cap) {
    $chatId = (int)$cap['chat_id'];
    $userId = (int)$cap['user_id'];
    $msgId  = (int)$cap['message_id'];

    // Hapus pesan captcha
    if ($msgId) {
        telegramApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
    }

    // Kick user (ban sementara 40 detik = kick di Telegram)
    $kickResult = telegramApi('banChatMember', [
        'chat_id'    => $chatId,
        'user_id'    => $userId,
        'until_date' => time() + 40,
    ]);

    // Kirim notifikasi ke grup
    if ($kickResult['ok'] ?? false) {
        $name    = !empty($cap['first_name']) ? htmlspecialchars($cap['first_name']) : "#$userId";
        $mention = "<a href=\"tg://user?id=$userId\">$name</a>";
        loadGroupLang($db, $chatId);
        telegramApi('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => Lang::t('cron.captcha_kick', ['mention' => $mention]),
            'parse_mode' => 'HTML',
        ]);
        $log[] = "[CAPTCHA-KICK] chatId=$chatId userId=$userId name=$name";
    } else {
        $log[] = "[CAPTCHA-KICK-FAIL] chatId=$chatId userId=$userId err=" . ($kickResult['description'] ?? '?');
    }

    // Hapus dari DB
    $db->execute(
        "DELETE FROM captcha_pending WHERE chat_id = ? AND user_id = ?",
        [$chatId, $userId]
    );
}

// ================================================================
// 2. UNMUTE USER YANG MASA MUTE-NYA SUDAH HABIS
// ================================================================
$expiredMutes = $db->query(
    "SELECT * FROM mutes WHERE expires_at > 0 AND expires_at < ?",
    [time()]
);

foreach ($expiredMutes as $mute) {
    $chatId = (int)$mute['chat_id'];
    $userId = (int)$mute['user_id'];

    $r = telegramApi('restrictChatMember', [
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
    ]);

    if ($r['ok'] ?? false) {
        $log[] = "[UNMUTE] chatId=$chatId userId=$userId";
        $mention = "<a href=\"tg://user?id=$userId\">User #$userId</a>";
        loadGroupLang($db, $chatId);
        telegramApi('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => Lang::t('cron.mute_expired', ['mention' => $mention]),
            'parse_mode' => 'HTML',
        ]);
    }

    $db->execute("DELETE FROM mutes WHERE id = ?", [$mute['id']]);
}

// ================================================================
// 3. UNBAN USER YANG MASA BAN SEMENTARA SUDAH HABIS
// ================================================================
$expiredBans = $db->query(
    "SELECT * FROM bans WHERE expires_at > 0 AND expires_at < ?",
    [time()]
);

foreach ($expiredBans as $ban) {
    $chatId = (int)$ban['chat_id'];
    $userId = (int)$ban['user_id'];

    $r = telegramApi('unbanChatMember', [
        'chat_id'        => $chatId,
        'user_id'        => $userId,
        'only_if_banned' => true,
    ]);

    if ($r['ok'] ?? false) {
        $log[] = "[UNBAN] chatId=$chatId userId=$userId";
    }

    $db->execute("DELETE FROM bans WHERE id = ?", [$ban['id']]);
}

// ================================================================
// 4. TUTUP SESI VOTE KICK YANG SUDAH EXPIRED
// ================================================================
$expiredVotekicks = $db->getExpiredVotekickSessions();

foreach ($expiredVotekicks as $vk) {
    $chatId    = (int)$vk['chat_id'];
    $sessionId = (int)$vk['id'];
    $tnam      = htmlspecialchars($vk['target_name'] ?: "User #{$vk['target_id']}");
    $yes       = (int)$vk['yes_count'];
    $no        = (int)$vk['no_count'];
    $thresh    = (int)$vk['threshold'];

    $db->updateVotekickStatus($sessionId, 'expired');

    loadGroupLang($db, $chatId);
    $expiredTxt = Lang::t('vk.expired_text', [
        'target' => $tnam,
        'yes'    => $yes,
        'no'     => $no,
        'thresh' => $thresh,
    ]);

    if ($vk['message_id']) {
        $r = telegramApi('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => (int)$vk['message_id'],
            'text'       => $expiredTxt,
            'parse_mode' => 'HTML',
        ]);
        if (!($r['ok'] ?? false)) {
            // Jika gagal edit (pesan sudah dihapus), kirim pesan baru
            telegramApi('sendMessage', [
                'chat_id'    => $chatId,
                'text'       => $expiredTxt,
                'parse_mode' => 'HTML',
            ]);
        }
    }

    $log[] = "[VOTEKICK-EXPIRE] chatId=$chatId sessionId=$sessionId target={$vk['target_id']} yes=$yes/$thresh";
}

// ================================================================
// 5. BERSIHKAN DATA LAMA (Housekeeping)
// ================================================================
// Hapus flood tracker yang sudah lama (> 5 menit)
$db->execute("DELETE FROM flood_tracker WHERE window_start < ?", [time() - 300]);
// Hapus join tracker yang sudah lama (> 5 menit)
$db->execute("DELETE FROM join_tracker WHERE joined_at < ?", [time() - 300]);
// Hapus spam tracker yang sudah lama (> 5 menit — window deteksi spam adalah 60 detik)
$db->execute("DELETE FROM spam_tracker WHERE sent_at < ?", [time() - 300]);
// Hapus pending input yang sudah expired (> 10 menit)
$db->execute("DELETE FROM pending_input WHERE created_at < ?", [time() - 600]);
// Hapus message_log yang sudah > 48 jam (Telegram tidak bisa hapus pesan lebih tua dari itu)
$db->execute("DELETE FROM message_log WHERE sent_at < ?", [time() - 172800]);
$log[] = "[CLEANUP] message_log: hapus entri kadaluarsa (>48 jam)";
// Hapus processed_updates yang sudah > 24 jam (deduplikasi update Telegram)
// Cleanup ini sebelumnya dilakukan inline di checkAndMarkUpdateId() setiap request,
// menyebabkan DELETE besar di setiap webhook call. Dipindahkan ke cron agar aman.
$db->execute("DELETE FROM processed_updates WHERE processed_at < ?", [time() - 86400]);
$log[] = "[CLEANUP] processed_updates: hapus entri >24 jam";
// Hapus percakapan AI > 7 hari (cegah tabel membengkak)
$aiCleaned = $db->cleanupAiConversations(7);
if ($aiCleaned > 0) $log[] = "[CLEANUP] Hapus $aiCleaned baris ai_conversations lama";
// Hapus sesi votekick selesai/expired > 7 hari
$db->cleanupOldVotekickSessions(7);
// Hapus riwayat broadcast > 30 hari
$db->cleanupBroadcastHistory(30);
// Hapus warnings > 1 tahun (catatan lama di grup aktif bisa menumpuk ribuan baris)
$db->execute("DELETE FROM warnings WHERE warned_at < ?", [time() - (365 * 86400)]);
$log[] = "[CLEANUP] warnings: hapus warn > 1 tahun";

// ================================================================
// 6. POLLING INBOX TEMPMAIL — Notifikasi email baru
// ================================================================
try {
    // Ambil semua sesi tempmail aktif dari bot_settings
    $tmSessions = $db->query(
        "SELECT key, value FROM bot_settings WHERE key LIKE 'tm_sess_%'"
    );

    // Batasi maks 8 user per run agar tidak timeout
    $tmSessions = array_slice($tmSessions, 0, 8);

    foreach ($tmSessions as $row) {
        $key    = $row['key'];
        $userId = (int) str_replace('tm_sess_', '', $key);
        if (!$userId) continue;

        $sess = json_decode($row['value'], true);
        if (!is_array($sess) || empty($sess['email']) || empty($sess['chat_id'])) continue;

        $chatId = (int) $sess['chat_id'];

        // Lewati jika email sudah expired
        if (!empty($sess['expires_at'])) {
            $exp = is_numeric($sess['expires_at']) ? (int)$sess['expires_at'] : strtotime($sess['expires_at']);
            if ($exp && $exp < time()) {
                $log[] = "[TM-POLL] userId=$userId email={$sess['email']} — EXPIRED, skip";
                continue;
            }
        }

        try {
            // Ambil ID pesan yang sudah pernah dinotifikasi
            $seenKey  = "tm_seen_{$userId}";
            $seenJson = $db->getBotSetting($seenKey, '[]');
            $seenIds  = json_decode($seenJson, true) ?: [];

            // Hubungkan WS dan ambil inbox
            $sessFile = sys_get_temp_dir() . '/tm_bot_' . $userId . '.json';
            if (!file_exists($sessFile)) {
                @file_put_contents($sessFile, json_encode([
                    'vtok'  => $sess['vtok']  ?? 'empty',
                    'utok'  => $sess['utok']  ?? null,
                    'utype' => $sess['utype'] ?? 'guest',
                ]));
            }

            $ws = new TempMailWS($sessFile);
            $ws->connect();
            $ws->init();
            $r = $ws->inbox('inbound');
            $ws->close();

            $msgs = $r['email_messages'] ?? $r['messages'] ?? [];
            if (!is_array($msgs)) $msgs = [];

            $newMsgs  = [];
            $allIds   = [];

            foreach ($msgs as $m) {
                $mid = $m['id'] ?? $m['_id'] ?? '';
                if (!$mid) continue;
                $allIds[] = $mid;
                $isRead = $m['read'] ?? $m['seen'] ?? $m['isRead'] ?? false;
                if (!in_array($mid, $seenIds, true) && !$isRead) {
                    $newMsgs[] = $m;
                }
            }

            // Simpan semua ID yang ada (agar tidak notif ulang)
            if ($allIds) {
                $db->setBotSetting($seenKey, json_encode(array_values(array_unique($allIds))));
            }

            // Kirim notifikasi untuk setiap pesan baru
            foreach ($newMsgs as $m) {
                $sender = $m['sender'] ?? null;
                $from   = is_array($sender) ? ($sender['text'] ?? $sender['address'] ?? '?')
                                            : ($sender ?? $m['emailFrom'] ?? $m['from_email'] ?? '?');
                $from   = mb_substr(htmlspecialchars($from), 0, 40);
                $subj   = mb_substr(htmlspecialchars($m['subject'] ?? '(Tidak ada subjek)'), 0, 60);
                $email  = htmlspecialchars($sess['email']);
                $mid    = $m['id'] ?? $m['_id'] ?? '';

                $text = "📬 <b>Email Baru Masuk!</b>\n\n"
                      . "📧 <code>{$email}</code>\n"
                      . "👤 <b>Dari:</b> <code>{$from}</code>\n"
                      . "📌 <b>Subjek:</b> {$subj}";

                $kb = [
                    [['text' => '📖 Baca Pesan', 'callback_data' => 'tm:read:' . $mid]],
                    [['text' => '📥 Lihat Inbox', 'callback_data' => 'tm:inbox']],
                ];

                telegramApi('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $kb],
                ]);

                $log[] = "[TM-NOTIFY] userId=$userId chatId=$chatId subj=\"{$m['subject']}\"";
            }

            if (empty($newMsgs)) {
                $total = count($msgs);
                $log[] = "[TM-POLL] userId=$userId email={$sess['email']} — {$total} pesan, tidak ada yang baru";
            }

        } catch (\Throwable $e) {
            $log[] = "[TM-POLL-ERR] userId=$userId err=" . $e->getMessage();
        }
    }
} catch (\Throwable $e) {
    $log[] = "[TM-POLL-FATAL] " . $e->getMessage();
}

// ================================================================
// 7. BROADCAST TERJADWAL — Kirim antrian yang sudah waktunya
// ================================================================
try {
    $schedules = $db->getPendingSchedules();
    if (!empty($schedules)) {
        $allGroups = $db->getAllGroups();

        // Map media_type → Telegram API method + field name
        $mediaMap = [
            'photo'      => ['method' => 'sendPhoto',     'field' => 'photo'],
            'video'      => ['method' => 'sendVideo',     'field' => 'video'],
            'document'   => ['method' => 'sendDocument',  'field' => 'document'],
            'audio'      => ['method' => 'sendAudio',     'field' => 'audio'],
            'animation'  => ['method' => 'sendAnimation', 'field' => 'animation'],
            'voice'      => ['method' => 'sendVoice',     'field' => 'voice'],
            'video_note' => ['method' => 'sendVideoNote', 'field' => 'video_note'],
        ];

        foreach ($schedules as $sched) {
            $ok = $fail = 0;
            $mediaType = $sched['media_type'] ?? null;
            $fileId    = $sched['file_id']    ?? null;
            $caption   = $sched['caption']    ?? '';
            $message   = $sched['message']    ?? '';

            foreach ($allGroups as $g) {
                $gChatId = (int)$g['chat_id'];

                if ($mediaType && $fileId && isset($mediaMap[$mediaType])) {
                    $apiMethod = $mediaMap[$mediaType]['method'];
                    $apiField  = $mediaMap[$mediaType]['field'];
                    $params    = ['chat_id' => $gChatId, $apiField => $fileId];
                    if ($caption) { $params['caption'] = $caption; $params['parse_mode'] = 'HTML'; }
                    $r = telegramApi($apiMethod, $params);
                } else {
                    $r = telegramApi('sendMessage', [
                        'chat_id'    => $gChatId,
                        'text'       => $message,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }

                // Handle 429 Too Many Requests — Telegram memberitahu berapa detik harus tunggu
                // @see https://core.telegram.org/bots/faq#how-can-i-message-all-of-my-bot-39s-subscribers-at-once
                if (!($r['ok'] ?? false) && ($r['error_code'] ?? 0) === 429) {
                    $retryAfter = max(1, (int)($r['parameters']['retry_after'] ?? 5));
                    sleep($retryAfter);
                    $r = telegramApi($apiMethod ?? 'sendMessage', $params ?? [
                        'chat_id'    => $gChatId,
                        'text'       => $message,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }

                ($r['ok'] ?? false) ? $ok++ : $fail++;

                // Rate limit: 50ms delay antar grup = maks 20 msg/detik
                // Telegram global limit: ~30 msg/detik, 1 msg/detik per grup
                usleep(50000);
            }

            // Tandai sebagai terkirim & catat ke history
            $db->markScheduleSent((int)$sched['id']);
            $logMsg = $mediaType ? "[{$mediaType}]" . ($caption ? " {$caption}" : '') : $message;
            $db->execute(
                "INSERT INTO broadcast_history (sent_by, message, success_count, fail_count) VALUES (?, ?, ?, ?)",
                [(int)$sched['sent_by'], $logMsg, $ok, $fail]
            );

            // Kirim notifikasi ke SA yang membuat jadwal
            $schedAt = date('d/m/Y H:i', (int)$sched['scheduled_at']);
            telegramApi('sendMessage', [
                'chat_id'    => (int)$sched['sent_by'],
                'text'       => "✅ <b>Broadcast Terjadwal Terkirim!</b>\n\n🕐 Dijadwalkan: <b>{$schedAt}</b>\n📤 Berhasil: <b>{$ok}</b> | Gagal: <b>{$fail}</b>",
                'parse_mode' => 'HTML',
            ]);

            $log[] = "[SCHEDULE] id={$sched['id']} type=" . ($mediaType ?: 'text') . " ok={$ok} fail={$fail}";
        }
    }
} catch (\Throwable $e) {
    $log[] = "[SCHEDULE-ERR] " . $e->getMessage();
}

// ================================================================
// 8. PEMELIHARAAN SQLite — optimize query planner + WAL checkpoint
// ================================================================
try {
    $db->runMaintenance();
    $log[] = "[MAINTENANCE] PRAGMA optimize + wal_checkpoint(PASSIVE) selesai";
} catch (\Throwable $e) {
    $log[] = "[MAINTENANCE-ERR] " . $e->getMessage();
}

// ================================================================
// 9. ROTASI LOG FILE (Potong jika > 5 MB)
// ================================================================
if (defined('LOG_PATH') && file_exists(LOG_PATH) && filesize(LOG_PATH) > 5 * 1024 * 1024) {
    $lines    = file(LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $keep     = array_slice($lines, -500);
    file_put_contents(LOG_PATH, implode("\n", $keep) . "\n", LOCK_EX);
    $log[] = "[LOG-ROTATE] bot.log dipotong, simpan 500 baris terakhir";
}

// ================================================================
// OUTPUT LOG
// ================================================================
$elapsed = round(microtime(true) - $startTime, 3);
$ts      = date('Y-m-d H:i:s');

if (!empty($log)) {
    foreach ($log as $line) {
        echo "[$ts] $line\n";
    }
} else {
    echo "[$ts] Cron OK — tidak ada tugas (elapsed {$elapsed}s)\n";
}

// Simpan ke log file jika dipanggil via CLI
if (php_sapi_name() === 'cli' && defined('LOG_PATH')) {
    $summary = "[$ts] Cron: captcha=" . count($expiredCaptchas) . " mute=" . count($expiredMutes) . " ban=" . count($expiredBans) . " votekick=" . count($expiredVotekicks) . " ({$elapsed}s)\n";
    file_put_contents(LOG_PATH, $summary, FILE_APPEND | LOCK_EX);
}
