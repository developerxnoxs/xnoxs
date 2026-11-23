<?php
/**
 * ================================================================
 *  TEMPMAIL BOT HANDLER — Superadmin Panel
 *  Inline keyboard: Generate → Inbox → Read → Delete
 * ================================================================
 */

// ═══════════════════════════════════════════════════════════════════
//  WEBSOCKET CLIENT (slim, panel edition)
// ═══════════════════════════════════════════════════════════════════

class TempMailWS
{
    const HOST       = 'tm-app.solucioneswc.com';
    const PORT       = 2083;
    const PATH       = '/socket.io/';
    const ORIGIN     = 'https://tempmail.ninja';
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private $sock  = null;
    private int $n = 1;

    private string  $sessFile;
    private string  $vtok     = 'empty';
    private ?string $utok     = null;
    private string  $utype    = 'guest';

    public function __construct(string $sessFile)
    {
        $this->sessFile = $sessFile;
        $this->load();
    }

    public function __destruct() { $this->close(); }

    // ── Session ───────────────────────────────────────────────────

    private function load(): void
    {
        if (!file_exists($this->sessFile)) return;
        $d = json_decode(@file_get_contents($this->sessFile), true);
        if (is_array($d)) {
            $this->vtok  = $d['vtok']  ?? 'empty';
            $this->utok  = $d['utok']  ?? null;
            $this->utype = $d['utype'] ?? 'guest';
        }
    }

    public function save(): void
    {
        @file_put_contents($this->sessFile, json_encode([
            'vtok'  => $this->vtok,
            'utok'  => $this->utok,
            'utype' => $this->utype,
        ]));
    }

    private function setToken(?string $tok): void
    {
        if ($tok === null) return;
        if ($this->utype === 'registered') $this->utok = $tok;
        else $this->vtok = $tok;
        $this->save();
    }

    public function clear(): void
    {
        $this->vtok = 'empty'; $this->utok = null; $this->utype = 'guest';
        if (file_exists($this->sessFile)) @unlink($this->sessFile);
    }

    // ── WebSocket ─────────────────────────────────────────────────

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $this->sock = @stream_socket_client('ssl://' . self::HOST . ':' . self::PORT,
            $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException("Koneksi gagal: $errstr ($errno)");

        stream_set_timeout($this->sock, 20);

        $key = base64_encode(random_bytes(16));
        $path = self::PATH . '?EIO=4&transport=websocket&locale=en';
        fwrite($this->sock, implode("\r\n", [
            "GET $path HTTP/1.1",
            "Host: " . self::HOST . ':' . self::PORT,
            "Upgrade: websocket", "Connection: Upgrade",
            "Sec-WebSocket-Key: $key", "Sec-WebSocket-Version: 13",
            "Origin: " . self::ORIGIN,
            "User-Agent: " . self::USER_AGENT,
            "Referer: " . self::ORIGIN . '/',
            "Accept-Language: en-US,en;q=0.9", "", "",
        ]));

        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock, 4096); $resp .= $line;
            if ($line === "\r\n") break;
        }
        if (strpos($resp, '101') === false) throw new RuntimeException("WebSocket upgrade gagal");

        $open = $this->frame(); // Engine.io OPEN
        if (empty($open) || $open[0] !== '0') throw new RuntimeException("Engine.io OPEN tidak diterima");

        $this->send('40');
        for ($i = 0; $i < 10; $i++) {
            $f = $this->frame();
            if ($f === null) break;
            if ($f === '2') { $this->send('3'); continue; }
            if (str_starts_with($f, '40')) return;
        }
        throw new RuntimeException("Socket.io CONNECT ack tidak diterima");
    }

    public function close(): void
    {
        if ($this->sock) { @fwrite($this->sock, "\x88\x80" . random_bytes(4)); @fclose($this->sock); $this->sock = null; }
    }

    private function send(string $p): void
    {
        $l = strlen($p); $mk = random_bytes(4); $m = '';
        for ($i = 0; $i < $l; $i++) $m .= chr(ord($p[$i]) ^ ord($mk[$i % 4]));
        $h = chr(0x81);
        if ($l < 126) $h .= chr(0x80 | $l);
        elseif ($l < 65536) $h .= chr(0x80 | 126) . pack('n', $l);
        else $h .= chr(0x80 | 127) . pack('J', $l);
        fwrite($this->sock, $h . $mk . $m);
    }

    private function ping(int $op, string $p = ''): void
    {
        $l = min(strlen($p), 125); $p = substr($p, 0, $l); $mk = random_bytes(4); $m = '';
        for ($i = 0; $i < $l; $i++) $m .= chr(ord($p[$i]) ^ ord($mk[$i % 4]));
        fwrite($this->sock, chr(0x80 | $op) . chr(0x80 | $l) . $mk . $m);
    }

    private function frame(float $to = 15.0): ?string
    {
        $dl = microtime(true) + $to;
        while (microtime(true) < $dl) {
            $h = $this->rb(2); if (!$h || strlen($h) < 2) return null;
            $op = ord($h[0]) & 0x0F; $msk = (ord($h[1]) & 0x80) !== 0; $len = ord($h[1]) & 0x7F;
            if ($len === 126) { $e = $this->rb(2); if (!$e) return null; $len = unpack('n', $e)[1]; }
            elseif ($len === 127) { $e = $this->rb(8); if (!$e) return null; $len = unpack('J', $e)[1]; }
            $mk = $msk ? $this->rb(4) : '';
            $data = $len > 0 ? ($this->rb($len) ?? '') : '';
            if ($msk && $mk) { $u = ''; for ($i = 0; $i < strlen($data); $i++) $u .= chr(ord($data[$i]) ^ ord($mk[$i % 4])); $data = $u; }
            switch ($op) {
                case 0x0: case 0x1: case 0x2: return $data;
                case 0x8: $this->close(); return null;
                case 0x9: $this->ping(0xA, $data); continue 2;
                case 0xA: continue 2;
                default:  return $data;
            }
        }
        return null;
    }

    private function rb(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $c = fread($this->sock, $n - strlen($buf));
            if ($c === false || $c === '') {
                if (feof($this->sock)) return strlen($buf) ? $buf : null;
                if (stream_get_meta_data($this->sock)['timed_out'] ?? false) return null;
                usleep(5_000); continue;
            }
            $buf .= $c;
        }
        return $buf;
    }

    public function emit(string $ev, array $args = [], float $to = 20.0): mixed
    {
        $id  = $this->n++;
        $pay = '42' . $id . json_encode(array_merge([$ev], $args),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->send($pay);
        $pfx = '43' . $id; $dl = microtime(true) + $to;
        while (microtime(true) < $dl) {
            $f = $this->frame($dl - microtime(true));
            if ($f === null) break;
            if ($f === '2') { $this->send('3'); continue; }
            if (str_starts_with($f, $pfx)) {
                $dec = json_decode(substr($f, strlen($pfx)), true);
                return (is_array($dec) && count($dec) === 1) ? $dec[0] : $dec;
            }
        }
        throw new RuntimeException("Timeout '$ev'");
    }

    // ── API ───────────────────────────────────────────────────────

    public function init(): void
    {
        $tok = $this->utype === 'registered' && $this->utok ? $this->utok
             : ($this->vtok !== 'empty' ? $this->vtok : null);
        $r   = $this->emit('get_' . $this->utype . '_user_data', [['token' => $tok]]);
        if (!is_array($r)) return;
        if (isset($r['visitor_data'])) $this->utype = 'guest';
        elseif (isset($r['user_data'])) $this->utype = 'registered';
        if (isset($r['token'])) $this->setToken($r['token']);
        $this->save();
    }

    public function generate(): array
    {
        $r = $this->emit('generate_temp_mail');
        if (is_array($r)) $this->setToken($r['newToken'] ?? null);
        return is_array($r) ? $r : ['raw' => $r];
    }

    public function inbox(string $type = 'inbound'): array
    {
        $r = $this->emit('get_email_messages', [['messageType' => $type]], 30.0);
        return is_array($r) ? $r : ['raw' => $r];
    }

    public function message(string $id): array
    {
        $r = $this->emit('get_email_message', [['messageId' => $id]], 60.0);
        return is_array($r) ? $r : ['raw' => $r];
    }

    public function deleteMsg(string $id, string $type = 'inbound'): array
    {
        $r = $this->emit('delete_email_message', [['messageId' => $id, 'messageType' => $type]]);
        return is_array($r) ? $r : ['raw' => $r];
    }

    public function deleteEmail(string $email): array
    {
        $r = $this->emit('delete_email', [['emailAddress' => $email]]);
        return is_array($r) ? $r : ['raw' => $r];
    }
}

// ═══════════════════════════════════════════════════════════════════
//  TEMPMAIL BOT — Inline keyboard handler
// ═══════════════════════════════════════════════════════════════════

class TempMailBot
{
    // ── Telegram API helper ───────────────────────────────────────

    private static function tg(string $method, array $params = []): array
    {
        $ch = curl_init(API_URL . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    private static function send(int $chatId, string $text, array $kb = [], int $replyTo = 0): array
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($kb) $p['reply_markup'] = ['inline_keyboard' => $kb];
        if ($replyTo) $p['reply_to_message_id'] = $replyTo;
        return self::tg('sendMessage', $p);
    }

    private static function edit(int $chatId, int $msgId, string $text, array $kb = []): void
    {
        $p = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($kb) $p['reply_markup'] = ['inline_keyboard' => $kb];
        self::tg('editMessageText', $p);
    }

    private static function answer(string $cbId, string $text = '', bool $alert = false): void
    {
        self::tg('answerCallbackQuery', [
            'callback_query_id' => $cbId, 'text' => $text, 'show_alert' => $alert,
        ]);
    }

    private static function deleteMsg2(int $chatId, int $msgId): void
    {
        self::tg('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
    }

    // ── Session storage (bot_settings table) ─────────────────────

    private static function sessKey(int $userId): string { return "tm_sess_{$userId}"; }

    private static function sessFile(int $userId): string
    {
        return sys_get_temp_dir() . '/tm_bot_' . $userId . '.json';
    }

    private static function getSession(int $userId, $db): ?array
    {
        $row = $db->fetchOne("SELECT value FROM bot_settings WHERE key = ?", [self::sessKey($userId)]);
        return $row ? json_decode($row['value'], true) : null;
    }

    private static function saveSession(int $userId, array $data, $db): void
    {
        $db->execute(
            "INSERT OR REPLACE INTO bot_settings (key, value) VALUES (?, ?)",
            [self::sessKey($userId), json_encode($data)]
        );
    }

    private static function clearSession(int $userId, $db): void
    {
        $db->execute("DELETE FROM bot_settings WHERE key = ?", [self::sessKey($userId)]);
        $f = self::sessFile($userId);
        if (file_exists($f)) @unlink($f);
    }

    // ── WS helper: connect dengan token yang tersimpan ────────────

    private static function makeWS(int $userId, ?array $sess): TempMailWS
    {
        $f  = self::sessFile($userId);
        if ($sess && !file_exists($f)) {
            @file_put_contents($f, json_encode([
                'vtok'  => $sess['vtok']  ?? 'empty',
                'utok'  => $sess['utok']  ?? null,
                'utype' => $sess['utype'] ?? 'guest',
            ]));
        }
        $ws = new TempMailWS($f);
        $ws->connect();
        $ws->init();
        return $ws;
    }

    // ── Keyboards ─────────────────────────────────────────────────

    private static function kbMenu(): array
    {
        return [
            [['text' => '✨ Generate Email', 'callback_data' => 'tm:gen']],
            [['text' => '🔙 Superadmin', 'callback_data' => 'sa_panel'],
             ['text' => '❌ Tutup', 'callback_data' => 'tm:close']],
        ];
    }

    private static function kbActive(): array
    {
        return [
            [['text' => '📥 Cek Inbox', 'callback_data' => 'tm:inbox']],
            [['text' => '🗑️ Hapus Email', 'callback_data' => 'tm:destroy']],
            [['text' => '🔙 Superadmin', 'callback_data' => 'sa_panel'],
             ['text' => '❌ Tutup', 'callback_data' => 'tm:close']],
        ];
    }

    private static function kbInbox(array $messages): array
    {
        $rows = [];
        foreach (array_slice($messages, 0, 10) as $i => $m) {
            $id   = $m['id'] ?? $m['_id'] ?? '';
            $subj = mb_substr($m['subject'] ?? '(no subject)', 0, 28);
            $isRead = $m['read'] ?? $m['seen'] ?? $m['isRead'] ?? false;
            $new  = !$isRead ? '🔵 ' : '';
            $rows[] = [['text' => ($i + 1) . '. ' . $new . $subj, 'callback_data' => 'tm:read:' . $id]];
        }
        $rows[] = [
            ['text' => '🔄 Refresh', 'callback_data' => 'tm:inbox'],
            ['text' => '🔙 Kembali', 'callback_data' => 'tm:back'],
        ];
        return $rows;
    }

    private static function kbMsg(string $msgId): array
    {
        return [
            [['text' => '🗑️ Hapus Pesan', 'callback_data' => 'tm:delmsg:' . $msgId]],
            [['text' => '🔙 Inbox', 'callback_data' => 'tm:inbox']],
        ];
    }

    private static function kbConfirmDestroy(): array
    {
        return [
            [
                ['text' => '✅ Ya, Hapus Permanen', 'callback_data' => 'tm:destroy_yes'],
                ['text' => '❌ Batal', 'callback_data' => 'tm:back'],
            ],
        ];
    }

    // ── Text helpers ──────────────────────────────────────────────

    private static function menuText(): string
    {
        return "📬 <b>TempMail Manager</b>\n\n"
             . "Buat email sementara via TempMail Ninja.\n"
             . "Email aktif selama 24 jam (Quick plan, domain <code>.lat</code>).";
    }

    private static function activeText(array $sess): string
    {
        $exp = '';
        if (!empty($sess['expires_at'])) {
            $ts = strtotime($sess['expires_at']);
            if ($ts) $exp = "\n⏰ Kedaluwarsa: <code>" . date('d/m/Y H:i', $ts) . "</code>";
        }
        return "📬 <b>TempMail Aktif</b>\n\n"
             . "📧 <code>" . htmlspecialchars($sess['email']) . "</code>"
             . $exp;
    }

    // ── Entry points ──────────────────────────────────────────────

    /**
     * Dipanggil saat superadmin kirim /tempmail atau tekan tombol dari panel /sa
     */
    public static function handleCommand(int $chatId, int $userId, $db, int $replyToMsgId = 0): void
    {
        $sess = self::getSession($userId, $db);
        if ($sess && !empty($sess['email'])) {
            self::send($chatId, self::activeText($sess), self::kbActive(), $replyToMsgId);
        } else {
            self::send($chatId, self::menuText(), self::kbMenu(), $replyToMsgId);
        }
    }

    /**
     * Dipanggil saat ada callback_query dengan data diawali "tm:"
     *
     * @param string $data     callback_data penuh (misal "tm:gen", "tm:read:abc123")
     * @param int    $chatId
     * @param int    $userId
     * @param int    $msgId    message_id yang memiliki tombol
     * @param string $cbId     callback_query id untuk answerCallbackQuery
     * @param mixed  $db       Database instance
     */
    public static function handleCallback(
        string $data, int $chatId, int $userId,
        int $msgId, string $cbId, $db
    ): void {
        $parts  = explode(':', $data, 3); // ['tm', 'action', 'arg']
        $action = $parts[1] ?? '';
        $arg    = $parts[2] ?? '';

        switch ($action) {

            // ── Tampilkan menu utama ──────────────────────────────
            case 'back':
                $sess = self::getSession($userId, $db);
                if ($sess && !empty($sess['email'])) {
                    self::edit($chatId, $msgId, self::activeText($sess), self::kbActive());
                } else {
                    self::edit($chatId, $msgId, self::menuText(), self::kbMenu());
                }
                self::answer($cbId);
                break;

            // ── Tutup pesan ───────────────────────────────────────
            case 'close':
                self::deleteMsg2($chatId, $msgId);
                self::answer($cbId);
                break;

            // ── Generate email baru ───────────────────────────────
            case 'gen':
                self::answer($cbId, '⏳ Membuat email...');
                self::edit($chatId, $msgId, "⏳ <b>Membuat email sementara...</b>\n\nMohon tunggu, menghubungkan ke TempMail Ninja...", []);

                try {
                    $ws = self::makeWS($userId, null);
                    $r  = $ws->generate();
                    $ws->close();

                    $email = $r['emailData']['emailAddress'] ?? $r['emailAddress'] ?? '';
                    if (!$email) throw new RuntimeException($r['error'] ?? 'Email kosong');

                    $f    = self::sessFile($userId);
                    $wdat = file_exists($f) ? json_decode(@file_get_contents($f), true) : [];

                    $sess = [
                        'email'      => $email,
                        'expires_at' => $r['emailData']['expiresAt'] ?? ($r['expiresAt'] ?? ''),
                        'created_at' => time(),
                        'chat_id'    => $chatId,
                        'vtok'       => $wdat['vtok']  ?? 'empty',
                        'utok'       => $wdat['utok']  ?? null,
                        'utype'      => $wdat['utype'] ?? 'guest',
                    ];
                    self::saveSession($userId, $sess, $db);
                    self::edit($chatId, $msgId, self::activeText($sess), self::kbActive());

                } catch (\Throwable $e) {
                    self::edit($chatId, $msgId,
                        "❌ <b>Gagal membuat email</b>\n\n<code>" . htmlspecialchars($e->getMessage()) . "</code>",
                        [[['text' => '🔄 Coba Lagi', 'callback_data' => 'tm:gen'],
                          ['text' => '❌ Tutup',    'callback_data' => 'tm:close']]]
                    );
                }
                break;

            // ── Cek inbox ─────────────────────────────────────────
            case 'inbox':
                $sess = self::getSession($userId, $db);
                if (!$sess || empty($sess['email'])) {
                    self::answer($cbId, '❌ Tidak ada email aktif', true);
                    break;
                }
                self::answer($cbId, '⏳ Mengambil inbox...');
                self::edit($chatId, $msgId,
                    "📥 <b>Memuat inbox...</b>\n\n<code>" . htmlspecialchars($sess['email']) . "</code>", []);

                try {
                    $ws   = self::makeWS($userId, $sess);
                    $r    = $ws->inbox('inbound');
                    $ws->close();

                    $msgs = $r['email_messages'] ?? $r['messages'] ?? (is_array($r) && isset($r[0]) ? $r : []);
                    if (!is_array($msgs)) $msgs = [];

                    if (empty($msgs)) {
                        self::edit($chatId, $msgId,
                            "📥 <b>Inbox</b> — <code>" . htmlspecialchars($sess['email']) . "</code>\n\n"
                            . "📭 Inbox kosong. Kirim email ke alamat di atas.",
                            [
                                [['text' => '🔄 Refresh', 'callback_data' => 'tm:inbox']],
                                [['text' => '🔙 Kembali', 'callback_data' => 'tm:back']],
                            ]
                        );
                    } else {
                        $newCount = count(array_filter($msgs, fn($m) => !($m['read'] ?? $m['seen'] ?? $m['isRead'] ?? false)));
                        $total    = count($msgs);
                        $header   = "📥 <b>Inbox</b> — <code>" . htmlspecialchars($sess['email']) . "</code>\n"
                                  . "📊 {$total} pesan" . ($newCount ? ", 🔵 {$newCount} baru" : '') . "\n\n";

                        $list = '';
                        foreach (array_slice($msgs, 0, 10) as $i => $m) {
                            $isRead = $m['read'] ?? $m['seen'] ?? $m['isRead'] ?? false;
                            $new    = !$isRead ? '🔵 ' : '⚪ ';
                            $sender = $m['sender'] ?? null;
                            $from   = is_array($sender) ? ($sender['text'] ?? $sender['address'] ?? '?') : ($sender ?? $m['emailFrom'] ?? $m['from_email'] ?? '?');
                            $from   = mb_substr($from, 0, 22);
                            $subj   = mb_substr($m['subject'] ?? '(no subject)', 0, 30);
                            $list  .= ($i + 1) . ". {$new}<b>" . htmlspecialchars($subj) . "</b>\n"
                                    . "   📨 " . htmlspecialchars($from) . "\n";
                        }
                        if ($total > 10) $list .= "\n<i>... dan " . ($total - 10) . " pesan lainnya</i>";

                        self::edit($chatId, $msgId, $header . $list, self::kbInbox($msgs));
                    }

                } catch (\Throwable $e) {
                    self::edit($chatId, $msgId,
                        "❌ Gagal memuat inbox:\n<code>" . htmlspecialchars($e->getMessage()) . "</code>",
                        [
                            [['text' => '🔄 Coba Lagi', 'callback_data' => 'tm:inbox']],
                            [['text' => '🔙 Kembali',  'callback_data' => 'tm:back']],
                        ]
                    );
                }
                break;

            // ── Baca pesan ────────────────────────────────────────
            case 'read':
                if (!$arg) { self::answer($cbId, '❌ ID pesan tidak valid', true); break; }
                $sess = self::getSession($userId, $db);
                if (!$sess || empty($sess['email'])) {
                    self::answer($cbId, '❌ Tidak ada email aktif', true); break;
                }
                self::answer($cbId, '⏳ Membuka pesan...');
                self::edit($chatId, $msgId, "✉️ <b>Membuka pesan...</b>", []);

                try {
                    $ws = self::makeWS($userId, $sess);
                    $r  = $ws->message($arg);
                    $ws->close();

                    if (!empty($r['error'])) throw new RuntimeException($r['error']);

                    // Actual response: {emailMessage: {data: {content, subject, from, to, date}}}
                    $data = $r['emailMessage']['data'] ?? $r;

                    // from/to: can be string or {text: '...'}
                    $rawFrom = $data['from'] ?? $r['emailFrom'] ?? $r['from_email'] ?? '—';
                    $rawTo   = $data['to']   ?? $r['emailTo']   ?? $r['to_email']   ?? $sess['email'];
                    $from    = htmlspecialchars(is_array($rawFrom) ? ($rawFrom['text'] ?? $rawFrom['address'] ?? '—') : $rawFrom);
                    $to      = htmlspecialchars(is_array($rawTo)   ? ($rawTo['text']   ?? $rawTo['address']   ?? $sess['email']) : $rawTo);
                    $subj    = htmlspecialchars($data['subject']   ?? $r['subject']   ?? '(Tidak ada subjek)');
                    $date    = htmlspecialchars($data['date']      ?? $data['createdAt'] ?? $r['createdAt'] ?? $r['date'] ?? '—');

                    // Body: content field is HTML — strip tags for Telegram
                    $bodyRaw = $data['content'] ?? $data['bodyText'] ?? $data['text']
                             ?? $r['bodyText'] ?? $r['text'] ?? $r['body'] ?? '';
                    if (empty($bodyRaw)) {
                        $bodyRaw = $data['bodyHtml'] ?? $data['html'] ?? $r['bodyHtml'] ?? $r['html'] ?? '';
                    }
                    $bodyRaw = strip_tags($bodyRaw);
                    $bodyRaw = html_entity_decode(trim($bodyRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    $maxBody = 3000;
                    $truncated = false;
                    if (mb_strlen($bodyRaw) > $maxBody) {
                        $bodyRaw   = mb_substr($bodyRaw, 0, $maxBody);
                        $truncated = true;
                    }

                    $text = "✉️ <b>Isi Pesan</b>\n\n"
                          . "👤 <b>Dari:</b> <code>{$from}</code>\n"
                          . "📬 <b>Ke:</b> <code>{$to}</code>\n"
                          . "📌 <b>Subjek:</b> {$subj}\n"
                          . "🕒 <b>Tanggal:</b> {$date}\n\n"
                          . "─────────────────────\n"
                          . htmlspecialchars($bodyRaw, ENT_QUOTES, 'UTF-8')
                          . ($truncated ? "\n\n<i>... (pesan dipotong)</i>" : '');

                    self::edit($chatId, $msgId, $text, self::kbMsg($arg));

                } catch (\Throwable $e) {
                    self::edit($chatId, $msgId,
                        "❌ Gagal membaca pesan:\n<code>" . htmlspecialchars($e->getMessage()) . "</code>",
                        [
                            [['text' => '🔙 Inbox', 'callback_data' => 'tm:inbox']],
                        ]
                    );
                }
                break;

            // ── Hapus satu pesan ──────────────────────────────────
            case 'delmsg':
                if (!$arg) { self::answer($cbId, '❌ ID tidak valid', true); break; }
                $sess = self::getSession($userId, $db);
                if (!$sess) { self::answer($cbId, '❌ Sesi tidak ditemukan', true); break; }
                self::answer($cbId, '⏳ Menghapus...');

                try {
                    $ws = self::makeWS($userId, $sess);
                    $ws->deleteMsg($arg);
                    $ws->close();
                    self::tg('editMessageText', [
                        'chat_id' => $chatId, 'message_id' => $msgId,
                        'text'    => "✅ <b>Pesan dihapus.</b>",
                        'parse_mode' => 'HTML',
                        'reply_markup' => ['inline_keyboard' => [
                            [['text' => '📥 Lihat Inbox', 'callback_data' => 'tm:inbox']],
                            [['text' => '🔙 Kembali',    'callback_data' => 'tm:back']],
                        ]],
                    ]);
                } catch (\Throwable $e) {
                    self::answer($cbId, '❌ Gagal: ' . $e->getMessage(), true);
                }
                break;

            // ── Konfirmasi hapus email ────────────────────────────
            case 'destroy':
                $sess = self::getSession($userId, $db);
                if (!$sess || empty($sess['email'])) {
                    self::answer($cbId, '❌ Tidak ada email aktif', true); break;
                }
                self::answer($cbId);
                self::edit($chatId, $msgId,
                    "⚠️ <b>Hapus Email?</b>\n\n"
                    . "📧 <code>" . htmlspecialchars($sess['email']) . "</code>\n\n"
                    . "Email dan semua pesan di inbox akan dihapus <b>permanen</b>.",
                    self::kbConfirmDestroy()
                );
                break;

            // ── Eksekusi hapus email ──────────────────────────────
            case 'destroy_yes':
                $sess = self::getSession($userId, $db);
                if (!$sess || empty($sess['email'])) {
                    self::answer($cbId, '❌ Tidak ada email aktif', true); break;
                }
                self::answer($cbId, '⏳ Menghapus email...');
                self::edit($chatId, $msgId, "🗑️ <b>Menghapus email...</b>", []);

                $email = $sess['email'];
                try {
                    $ws = self::makeWS($userId, $sess);
                    $ws->deleteEmail($email);
                    $ws->close();
                    $ws->clear();
                } catch (\Throwable) {
                    // Tetap lanjut bersihkan sesi lokal meski WS gagal
                }

                self::clearSession($userId, $db);
                self::edit($chatId, $msgId,
                    "✅ <b>Email Dihapus</b>\n\n"
                    . "📧 <code>" . htmlspecialchars($email) . "</code>\n"
                    . "Email telah dihapus dari TempMail Ninja.",
                    [[['text' => '✨ Buat Email Baru', 'callback_data' => 'tm:gen'],
                      ['text' => '❌ Tutup',          'callback_data' => 'tm:close']]]
                );
                break;

            default:
                self::answer($cbId, '❓ Aksi tidak dikenal', true);
        }
    }
}
