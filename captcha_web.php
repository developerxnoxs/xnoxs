<?php
/**
 * Halaman Verifikasi hCaptcha untuk anggota grup Telegram baru.
 * Pengguna diarahkan ke halaman ini melalui tombol inline di Telegram.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lang.php';

$token = trim($_GET['token'] ?? '');

// Fungsi helper: kirim request ke Telegram Bot API
function callTelegramAPI(string $method, array $params): array {
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

// Fungsi helper: unmute user di grup
function unmuteUser(int $chatId, int $userId): bool {
    $r = callTelegramAPI('restrictChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
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
    return !empty($r['ok']);
}

// ================================================================
// Proses verifikasi POST
// ================================================================
$verified  = false;
$error     = '';
$firstName = '';
$hasRules  = false;

if (!empty($token) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hResponse = trim($_POST['h-captcha-response'] ?? '');

    if (empty($hResponse)) {
        $error = 'Silakan selesaikan captcha terlebih dahulu.';
    } elseif (HCAPTCHA_SECRET === '') {
        $error = 'hCaptcha belum dikonfigurasi di server ini.';
    } else {
        // Verifikasi token hCaptcha ke server hCaptcha
        $ch = curl_init('https://hcaptcha.com/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => HCAPTCHA_SECRET,
                'response' => $hResponse,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $verifyResult = json_decode(curl_exec($ch) ?: '{}', true) ?: [];
        curl_close($ch);

        if (!empty($verifyResult['success'])) {
            $db = Database::getInstance();
            $pending = $db->fetchOne(
                "SELECT * FROM captcha_pending WHERE hcaptcha_token = ?",
                [$token]
            );

            if (!$pending) {
                $error = 'Sesi verifikasi tidak ditemukan atau sudah kedaluwarsa.';
            } elseif (time() > (int)$pending['expires_at']) {
                // Hapus data kadaluarsa
                $db->execute("DELETE FROM captcha_pending WHERE hcaptcha_token = ?", [$token]);
                $error = 'Waktu verifikasi sudah habis. Anda perlu meninggalkan dan bergabung kembali ke grup.';
            } else {
                $chatId    = (int)$pending['chat_id'];
                $userId    = (int)$pending['user_id'];
                $firstName = htmlspecialchars($pending['first_name'] ?? 'Pengguna');
                $msgId     = (int)$pending['message_id'];

                // Hapus pesan captcha di grup
                if ($msgId) {
                    callTelegramAPI('deleteMessage', [
                        'chat_id'    => $chatId,
                        'message_id' => $msgId,
                    ]);
                }

                // Hapus dari tabel pending
                $db->execute("DELETE FROM captcha_pending WHERE hcaptcha_token = ?", [$token]);

                // Ambil settings grup & cek apakah ada peraturan
                $settings = $db->getGroupSettings($chatId);
                $rules    = trim($settings['rules'] ?? '');
                Lang::load($settings['language'] ?? 'id');

                if (!empty($rules)) {
                    // Ada peraturan → JANGAN unmute dulu.
                    // Tampilkan peraturan di grup dengan tombol setuju,
                    // sama seperti alur captcha matematika.
                    $hasRules  = true;
                    $rulesText = Lang::t('captcha.rules_text', [
                        'name'  => $firstName,
                        'rules' => $rules,
                    ]);
                    $kb = json_encode(['inline_keyboard' => [[
                        ['text' => Lang::t('captcha.rules_agree_btn'), 'callback_data' => "rules_agree:{$userId}"],
                    ]]]);
                    callTelegramAPI('sendMessage', [
                        'chat_id'                  => $chatId,
                        'text'                     => $rulesText,
                        'parse_mode'               => 'HTML',
                        'reply_markup'             => $kb,
                        'disable_web_page_preview' => true,
                    ]);
                } else {
                    // Tidak ada peraturan → langsung unmute dan kirim notifikasi
                    unmuteUser($chatId, $userId);
                    callTelegramAPI('sendMessage', [
                        'chat_id'    => $chatId,
                        'text'       => "✅ <b>$firstName</b> telah berhasil diverifikasi dan dapat mengirim pesan.",
                        'parse_mode' => 'HTML',
                    ]);
                }

                $verified = true;
            }
        } else {
            $error = 'Verifikasi captcha gagal. Silakan coba lagi.';
        }
    }
}

// Validasi token sebelum tampilkan halaman
$pendingInfo = null;
if (!$verified && empty($error) && !empty($token)) {
    try {
        $db = Database::getInstance();
        $pendingInfo = $db->fetchOne(
            "SELECT * FROM captcha_pending WHERE hcaptcha_token = ?",
            [$token]
        );
        if ($pendingInfo && time() > (int)$pendingInfo['expires_at']) {
            $pendingInfo = null;
            $error = 'Waktu verifikasi sudah habis. Anda perlu meninggalkan dan bergabung kembali ke grup.';
        }
    } catch (Throwable $e) {
        $error = 'Terjadi kesalahan. Silakan coba lagi.';
    }
}

if (empty($token)) {
    $error = 'Link verifikasi tidak valid.';
}

$siteKey = HCAPTCHA_SITEKEY;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keanggotaan Grup Telegram</title>
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 22px; color: #1a1a2e; margin-bottom: 8px; }
        .subtitle { color: #6b7280; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
        .name { color: #2563eb; font-weight: 600; }
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
            text-align: left;
        }
        .success-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 8px;
            padding: 20px;
            font-size: 15px;
            margin-top: 16px;
        }
        .success-box .check { font-size: 40px; margin-bottom: 8px; }
        .hcaptcha-wrapper { display: flex; justify-content: center; margin-bottom: 20px; }
        .btn {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        .btn:hover { background: #1d4ed8; }
        .note { font-size: 12px; color: #9ca3af; margin-top: 16px; }
    </style>
</head>
<body>
<div class="card">

<?php if ($verified): ?>
    <div class="success-box">
        <div class="check">✅</div>
        <strong>Verifikasi Berhasil!</strong><br><br>
        Halo <span class="name"><?= $firstName ?></span>!<br><br>
        <?php if ($hasRules): ?>
            Silakan <strong>kembali ke Telegram</strong> dan baca peraturan grup, lalu tekan tombol <strong>✅ Saya Setuju</strong> untuk membuka akses mengirim pesan.
        <?php else: ?>
            Anda sekarang dapat mengirim pesan di grup.
        <?php endif; ?>
    </div>
    <p class="note" style="margin-top:20px;">
        <?php if ($hasRules): ?>
            Setelah menyetujui peraturan di Telegram, Anda dapat langsung mengirim pesan.
        <?php else: ?>
            Anda dapat menutup halaman ini dan kembali ke Telegram.
        <?php endif; ?>
    </p>

<?php elseif (!empty($error)): ?>
    <div class="icon">🔐</div>
    <h1>Verifikasi Keanggotaan</h1>
    <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
    <p class="note">Jika Anda membutuhkan bantuan, hubungi admin grup.</p>

<?php elseif ($pendingInfo): ?>
    <div class="icon">🤖</div>
    <h1>Verifikasi Keanggotaan</h1>
    <p class="subtitle">
        Halo <span class="name"><?= htmlspecialchars($pendingInfo['first_name'] ?? 'Pengguna') ?></span>!<br>
        Selesaikan verifikasi di bawah untuk dapat mengirim pesan di grup Telegram.
    </p>

    <?php if (!empty($error)): ?>
        <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="hcaptcha-wrapper">
            <div class="h-captcha" data-sitekey="<?= htmlspecialchars($siteKey) ?>"></div>
        </div>
        <button type="submit" class="btn">✅ Verifikasi & Masuk Grup</button>
    </form>
    <p class="note">Verifikasi ini memastikan Anda bukan bot. Data Anda aman.</p>

<?php else: ?>
    <div class="icon">❌</div>
    <h1>Link Tidak Valid</h1>
    <p class="subtitle">Link verifikasi ini tidak valid atau sudah kedaluwarsa.</p>
    <p class="note">Silakan hubungi admin grup untuk bantuan lebih lanjut.</p>
<?php endif; ?>

</div>
</body>
</html>
