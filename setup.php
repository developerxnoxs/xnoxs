<?php
/**
 * Setup Webhook Telegram Bot
 * CLI : php setup.php set https://domain.com/webhook.php
 * Browser: https://domain.com/setup.php?token=<sha1(BOT_TOKEN)>
 */

require_once __DIR__ . '/config.php';

$isCli = (php_sapi_name() === 'cli');

// ── Keamanan browser ─────────────────────────────────────────────────────────
if (!$isCli) {
    $setupToken = $_GET['token'] ?? '';
    if (empty($setupToken) || $setupToken !== sha1(BOT_TOKEN)) {
        http_response_code(403);
        $hint = sha1(BOT_TOKEN);
        die("<!DOCTYPE html><html><head><meta charset='utf-8'><title>Forbidden</title>"
          . "<style>body{font-family:monospace;padding:2rem;background:#1a1a2e;color:#e94560;}"
          . "code{background:#16213e;padding:.2rem .5rem;border-radius:4px;color:#0f3460;}</style></head>"
          . "<body><h2>403 Forbidden</h2><p>Token tidak valid atau tidak diberikan.</p>"
          . "<p>Akses via: <code>setup.php?token={$hint}</code></p></body></html>");
    }
}

// ── Fungsi API Telegram ───────────────────────────────────────────────────────
function callApi(string $method, array $params = []): array {
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

// ── Output CLI ────────────────────────────────────────────────────────────────
function cliOut(string $msg): void {
    echo $msg . PHP_EOL;
}

// ── MODE CLI ──────────────────────────────────────────────────────────────────
if ($isCli) {
    $action     = $argv[1] ?? 'set';
    $webhookUrl = $argv[2] ?? '';

    switch ($action) {
        case 'set':
            if (empty($webhookUrl)) {
                cliOut("❌ URL webhook tidak diberikan.");
                cliOut("Gunakan: php setup.php set https://domain.com/webhook.php");
                exit(1);
            }
            $params = ['url' => $webhookUrl, 'max_connections' => 40,
                       'allowed_updates' => ['message', 'callback_query', 'edited_message', 'channel_post', 'chat_member', 'my_chat_member']];
            if (WEBHOOK_SECRET !== '') $params['secret_token'] = WEBHOOK_SECRET;
            $result = callApi('setWebhook', $params);
            cliOut($result['ok'] ? "✅ Webhook berhasil diset ke: $webhookUrl" : "❌ Gagal: " . ($result['description'] ?? 'Unknown error'));
            break;

        case 'delete':
            $result = callApi('deleteWebhook');
            cliOut($result['ok'] ? "✅ Webhook berhasil dihapus!" : "❌ Gagal: " . ($result['description'] ?? ''));
            break;

        case 'info':
            $result = callApi('getWebhookInfo');
            $info   = $result['result'] ?? [];
            cliOut("📋 INFO WEBHOOK:");
            cliOut("  URL: " . ($info['url'] ?? 'Tidak ada'));
            cliOut("  Pending: " . ($info['pending_update_count'] ?? 0));
            cliOut("  Last Error: " . ($info['last_error_message'] ?? 'Tidak ada'));
            cliOut("  Max Connections: " . ($info['max_connections'] ?? 0));
            break;

        case 'botinfo':
            $result = callApi('getMe');
            $bot    = $result['result'] ?? [];
            cliOut("🤖 INFO BOT:");
            cliOut("  Nama: " . ($bot['first_name'] ?? '-'));
            cliOut("  Username: @" . ($bot['username'] ?? '-'));
            cliOut("  ID: " . ($bot['id'] ?? '-'));
            cliOut("  Bisa join grup: " . (($bot['can_join_groups'] ?? false) ? 'Ya' : 'Tidak'));
            break;

        default:
            cliOut("Perintah tidak dikenal: $action");
            cliOut("Gunakan: set | delete | info | botinfo");
            break;
    }
    exit(0);
}

// ── MODE BROWSER ──────────────────────────────────────────────────────────────

$token  = $_GET['token'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Auto-detect URL webhook berdasarkan domain saat ini
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? '';
$dir         = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$defaultUrl  = $scheme . '://' . $host . $dir . '/webhook.php';

// ── Proses aksi ───────────────────────────────────────────────────────────────
$result  = null;
$message = '';
$msgType = 'info'; // info | success | error

if ($action === 'set') {
    $webhookUrl = trim($_POST['url'] ?? $_GET['url'] ?? '');
    if (empty($webhookUrl)) {
        $message = '❌ URL webhook tidak boleh kosong.';
        $msgType = 'error';
    } else {
        $params = ['url' => $webhookUrl, 'max_connections' => 40,
                   'allowed_updates' => ['message', 'callback_query', 'edited_message', 'channel_post', 'chat_member', 'my_chat_member']];
        if (WEBHOOK_SECRET !== '') $params['secret_token'] = WEBHOOK_SECRET;
        $result  = callApi('setWebhook', $params);
        $message = $result['ok']
            ? '✅ Webhook berhasil diset ke: ' . htmlspecialchars($webhookUrl)
            : '❌ Gagal: ' . htmlspecialchars($result['description'] ?? 'Unknown error');
        $msgType = $result['ok'] ? 'success' : 'error';
    }
} elseif ($action === 'delete') {
    $result  = callApi('deleteWebhook');
    $message = $result['ok'] ? '✅ Webhook berhasil dihapus!' : '❌ Gagal: ' . htmlspecialchars($result['description'] ?? '');
    $msgType = $result['ok'] ? 'success' : 'error';
} elseif ($action === 'info') {
    $result  = callApi('getWebhookInfo');
    $msgType = 'info';
} elseif ($action === 'botinfo') {
    $result  = callApi('getMe');
    $msgType = 'info';
}

// ── Selalu ambil info webhook & bot untuk ditampilkan di halaman ──────────────
$webhookInfo = callApi('getWebhookInfo')['result'] ?? [];
$botInfo     = callApi('getMe')['result']          ?? [];

$whUrl      = $webhookInfo['url']                  ?? '';
$whPending  = $webhookInfo['pending_update_count'] ?? 0;
$whLastErr  = $webhookInfo['last_error_message']   ?? '';
$whLastTime = isset($webhookInfo['last_error_date'])
    ? date('d/m/Y H:i:s', $webhookInfo['last_error_date']) : '';
$whMaxConn  = $webhookInfo['max_connections']      ?? 0;
$whActive   = !empty($whUrl);

$botName    = htmlspecialchars($botInfo['first_name'] ?? '-');
$botUser    = htmlspecialchars($botInfo['username']   ?? '-');
$botId      = $botInfo['id'] ?? '-';

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup Webhook — <?= $botName ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: #0f0f1a;
    color: #e2e8f0;
    min-height: 100vh;
    padding: 1.5rem;
  }

  .container { max-width: 720px; margin: 0 auto; }

  /* ── Header ── */
  .header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border: 1px solid #334155;
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
  }
  .header-avatar {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; flex-shrink: 0;
  }
  .header-info h1 { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; }
  .header-info p  { font-size: 0.85rem; color: #94a3b8; margin-top: .15rem; }
  .badge {
    display: inline-block;
    font-size: 0.7rem; font-weight: 600;
    padding: .2rem .55rem;
    border-radius: 999px;
    margin-left: .4rem;
    vertical-align: middle;
  }
  .badge-green  { background: #064e3b; color: #6ee7b7; }
  .badge-red    { background: #450a0a; color: #fca5a5; }
  .badge-blue   { background: #1e3a5f; color: #93c5fd; }

  /* ── Cards ── */
  .card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
  }
  .card-title {
    font-size: .8rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em;
    color: #64748b; margin-bottom: 1rem;
  }

  /* ── Webhook status ── */
  .status-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .75rem;
  }
  @media(max-width:500px){ .status-grid { grid-template-columns: 1fr; } }
  .status-item label { display: block; font-size: .75rem; color: #64748b; margin-bottom: .2rem; }
  .status-item .val  {
    font-size: .9rem; color: #e2e8f0;
    background: #0f172a; border-radius: 8px;
    padding: .45rem .75rem;
    word-break: break-all;
  }
  .status-item.full   { grid-column: 1/-1; }
  .val-ok  { color: #6ee7b7 !important; }
  .val-err { color: #fca5a5 !important; }
  .val-dim { color: #475569 !important; font-style: italic; }

  /* ── Form ── */
  .form-group { margin-bottom: .9rem; }
  .form-group label {
    display: block; font-size: .8rem; color: #94a3b8;
    margin-bottom: .35rem; font-weight: 500;
  }
  .form-group input[type="text"] {
    width: 100%;
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 8px;
    color: #e2e8f0;
    font-size: .9rem;
    padding: .6rem .9rem;
    outline: none;
    transition: border-color .2s;
  }
  .form-group input[type="text"]:focus { border-color: #3b82f6; }

  /* ── Buttons ── */
  .btn-row { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .25rem; }
  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .85rem; font-weight: 600;
    padding: .55rem 1.1rem;
    border-radius: 8px; border: none;
    cursor: pointer; text-decoration: none;
    transition: opacity .15s, transform .1s;
  }
  .btn:hover  { opacity: .85; }
  .btn:active { transform: scale(.97); }
  .btn-primary  { background: #3b82f6; color: #fff; }
  .btn-danger   { background: #dc2626; color: #fff; }
  .btn-outline  {
    background: transparent; color: #94a3b8;
    border: 1px solid #334155;
  }
  .btn-outline:hover { border-color: #64748b; color: #e2e8f0; opacity: 1; }

  /* ── Alert ── */
  .alert {
    border-radius: 10px; padding: .85rem 1.1rem;
    font-size: .9rem; margin-bottom: 1.25rem;
    border-left: 4px solid;
  }
  .alert-success { background: #064e3b22; border-color: #10b981; color: #6ee7b7; }
  .alert-error   { background: #450a0a22; border-color: #ef4444; color: #fca5a5; }
  .alert-info    { background: #1e3a5f22; border-color: #3b82f6; color: #93c5fd; }

  /* ── CLI hint ── */
  .cli-box {
    background: #0f172a;
    border: 1px solid #1e3a5f;
    border-radius: 8px;
    padding: .75rem 1rem;
    font-family: monospace;
    font-size: .8rem;
    color: #64748b;
  }
  .cli-box span { color: #38bdf8; }
  .cli-box b    { color: #94a3b8; }

  /* ── Raw JSON toggle ── */
  details summary {
    cursor: pointer; font-size: .8rem; color: #475569;
    margin-top: .5rem; user-select: none;
  }
  details summary:hover { color: #64748b; }
  pre {
    background: #0f172a; border-radius: 8px;
    padding: .75rem; margin-top: .5rem;
    font-size: .75rem; color: #64748b;
    overflow-x: auto; white-space: pre-wrap; word-break: break-all;
  }

  .footer {
    text-align: center; font-size: .75rem; color: #334155;
    margin-top: 1.5rem;
  }
</style>
</head>
<body>
<div class="container">

  <!-- ── Header ── -->
  <div class="header">
    <div class="header-avatar">🤖</div>
    <div class="header-info">
      <h1><?= $botName ?>
        <span class="badge badge-blue">@<?= $botUser ?></span>
        <span class="badge badge-blue">ID: <?= $botId ?></span>
      </h1>
      <p>Telegram Bot Webhook Setup Panel</p>
    </div>
  </div>

  <!-- ── Alert pesan aksi ── -->
  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType === 'success' ? 'success' : ($msgType === 'error' ? 'error' : 'info') ?>">
    <?= $message ?>
  </div>
  <?php endif; ?>

  <!-- ── Status Webhook Saat Ini ── -->
  <div class="card">
    <div class="card-title">📡 Status Webhook</div>
    <div class="status-grid">
      <div class="status-item full">
        <label>URL Webhook</label>
        <div class="val <?= $whActive ? 'val-ok' : 'val-dim' ?>">
          <?= $whActive ? htmlspecialchars($whUrl) : 'Belum diset' ?>
          <?php if ($whActive): ?>
            <span class="badge badge-green">AKTIF</span>
          <?php else: ?>
            <span class="badge badge-red">NONAKTIF</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="status-item">
        <label>Pending Updates</label>
        <div class="val <?= $whPending > 0 ? 'val-err' : '' ?>"><?= $whPending ?></div>
      </div>
      <div class="status-item">
        <label>Max Connections</label>
        <div class="val"><?= $whMaxConn ?: '-' ?></div>
      </div>
      <?php if ($whLastErr): ?>
      <div class="status-item full">
        <label>Error Terakhir <?= $whLastTime ? "($whLastTime)" : '' ?></label>
        <div class="val val-err"><?= htmlspecialchars($whLastErr) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Form Set Webhook ── -->
  <div class="card">
    <div class="card-title">🔗 Set Webhook URL</div>
    <form method="post" action="setup.php?token=<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="action" value="set">
      <div class="form-group">
        <label>URL Webhook (harus HTTPS)</label>
        <input type="text" name="url"
               value="<?= htmlspecialchars($_POST['url'] ?? $defaultUrl) ?>"
               placeholder="https://domain.com/webhook.php"
               spellcheck="false" autocomplete="off">
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary">🚀 Set Webhook</button>
      </div>
    </form>
  </div>

  <!-- ── Aksi Lainnya ── -->
  <div class="card">
    <div class="card-title">⚙️ Aksi Lainnya</div>
    <div class="btn-row">
      <a href="setup.php?token=<?= htmlspecialchars($token) ?>&action=info"
         class="btn btn-outline">📋 Info Webhook</a>
      <a href="setup.php?token=<?= htmlspecialchars($token) ?>&action=botinfo"
         class="btn btn-outline">🤖 Info Bot</a>
      <a href="setup.php?token=<?= htmlspecialchars($token) ?>"
         class="btn btn-outline">🔄 Refresh</a>
      <a href="setup.php?token=<?= htmlspecialchars($token) ?>&action=delete"
         class="btn btn-danger"
         onclick="return confirm('Yakin ingin menghapus webhook?')">🗑️ Hapus Webhook</a>
    </div>

    <?php if ($result && in_array($action, ['info', 'botinfo'])): ?>
    <details open style="margin-top:1rem;">
      <summary>Lihat respons JSON lengkap</summary>
      <pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>
    <?php endif; ?>
  </div>

  <!-- ── CLI Hint ── -->
  <div class="card">
    <div class="card-title">💻 Penggunaan via CLI</div>
    <div class="cli-box">
      <b># Set webhook</b><br>
      <span>php setup.php set https://domain.com/webhook.php</span><br><br>
      <b># Hapus webhook</b><br>
      <span>php setup.php delete</span><br><br>
      <b># Lihat info webhook</b><br>
      <span>php setup.php info</span><br><br>
      <b># Info bot</b><br>
      <span>php setup.php botinfo</span>
    </div>
  </div>

  <div class="footer">
    🕒 <?= date('d/m/Y H:i:s') ?> &nbsp;|&nbsp;
    Token: <code style="color:#334155"><?= htmlspecialchars(substr($token, 0, 8)) ?>...</code>
  </div>

</div>
</body>
</html>
