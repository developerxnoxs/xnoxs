<?php
/**
 * ================================================================
 *  EMAIL MANAGER — Superadmin Panel
 *  cPanel API (UAPI) + IMAP via SSL Socket + SMTP via cURL
 *  Tidak memerlukan PHP imap extension
 * ================================================================
 */

class EmailManager {

    // ===== RUNTIME CONFIG (di-override dari DB via configure()) =====
    private static string $cpanelHost = '';
    private static int    $cpanelPort = 0;
    private static string $cpanelUser = '';
    private static string $cpanelPass = '';
    private static string $mailHost   = '';
    private static int    $imapPort   = 0;
    private static int    $smtpPort   = 0;

    /**
     * Isi runtime config dari DB (dipanggil di awal handle()).
     * Key yang kosong/nol tetap menggunakan nilai dari config.php.
     */
    public static function configure(array $cfg): void {
        self::$cpanelHost = $cfg['cpanel_host'] ?: CPANEL_HOST;
        self::$cpanelPort = (int)($cfg['cpanel_port'] ?: CPANEL_PORT);
        self::$cpanelUser = $cfg['cpanel_user'] ?: CPANEL_USER;
        self::$cpanelPass = $cfg['cpanel_pass'] ?: CPANEL_PASS;
        self::$mailHost   = $cfg['mail_host']   ?: MAIL_HOST;
        self::$imapPort   = (int)($cfg['imap_port'] ?: IMAP_PORT);
        self::$smtpPort   = (int)($cfg['smtp_port'] ?: SMTP_PORT);
    }

    public static function getRuntimeConfig(): array {
        return [
            'cpanel_host' => self::$cpanelHost ?: CPANEL_HOST,
            'cpanel_port' => self::$cpanelPort ?: CPANEL_PORT,
            'cpanel_user' => self::$cpanelUser ?: CPANEL_USER,
            'cpanel_pass' => self::$cpanelPass ?: CPANEL_PASS,
            'mail_host'   => self::$mailHost   ?: MAIL_HOST,
            'imap_port'   => self::$imapPort   ?: IMAP_PORT,
            'smtp_port'   => self::$smtpPort   ?: SMTP_PORT,
        ];
    }

    // ===== CPANEL API =====

    public static function cpanelAPI(string $module, string $func, array $params = []): array {
        $host = self::$cpanelHost ?: CPANEL_HOST;
        $port = self::$cpanelPort ?: CPANEL_PORT;
        $user = self::$cpanelUser ?: CPANEL_USER;
        $pass = self::$cpanelPass ?: CPANEL_PASS;

        $url = "https://{$host}:{$port}/execute/{$module}/{$func}";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "$user:$pass",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['errors' => [$err], 'status' => 0, 'data' => null];
        $decoded = json_decode($res ?: '{}', true);
        return $decoded ?: ['errors' => ['Invalid JSON'], 'status' => 0, 'data' => null];
    }

    public static function cpanelAPI2(string $module, string $func, array $params = []): array {
        $host = self::$cpanelHost ?: CPANEL_HOST;
        $port = self::$cpanelPort ?: CPANEL_PORT;
        $user = self::$cpanelUser ?: CPANEL_USER;
        $pass = self::$cpanelPass ?: CPANEL_PASS;

        $params = array_merge(['cpanel_jsonapi_module' => $module, 'cpanel_jsonapi_func' => $func, 'cpanel_jsonapi_apiversion' => '2'], $params);
        $url = "https://{$host}:{$port}/json-api/cpanel?" . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "$user:$pass",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['cpanelresult' => ['data' => []]];
        return json_decode($res ?: '{}', true) ?: [];
    }

    public static function listAccounts(): array {
        $res = self::cpanelAPI('Email', 'list_pops');
        if (!empty($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }
        $res2 = self::cpanelAPI2('Email', 'listpops');
        return $res2['cpanelresult']['data'] ?? [];
    }

    public static function createAccount(string $user, string $domain, string $password, int $quota = 500): array {
        return self::cpanelAPI('Email', 'add_pop', [
            'email'    => $user,
            'domain'   => $domain,
            'password' => $password,
            'quota'    => $quota,
        ]);
    }

    public static function deleteAccount(string $user, string $domain): array {
        return self::cpanelAPI('Email', 'delete_pop', [
            'email'  => $user,
            'domain' => $domain,
        ]);
    }

    public static function changePassword(string $user, string $domain, string $newPassword): array {
        return self::cpanelAPI('Email', 'passwd_pop', [
            'email'    => $user,
            'domain'   => $domain,
            'password' => $newPassword,
        ]);
    }

    public static function listDomains(): array {
        $res = self::cpanelAPI('Email', 'list_mail_domains');
        return $res['data'] ?? [];
    }

    // ===== IMAP via SSL Socket (tanpa imap extension) =====

    /**
     * Buka koneksi IMAP via SSL socket
     * Return: resource socket atau false
     */
    private static function imapConnect(string $email, string $password, string &$errMsg = '') {
        $host = self::$mailHost ?: MAIL_HOST;
        $port = self::$imapPort ?: IMAP_PORT;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $errMsg = "Tidak bisa terhubung ke server mail ({$host}:{$port}): {$errstr}";
            return false;
        }

        stream_set_timeout($socket, 20);

        // Baca greeting server
        $greeting = self::imapReadLine($socket);
        if (!$greeting || strpos($greeting, '* OK') === false) {
            fclose($socket);
            $errMsg = 'Server tidak merespons dengan benar.';
            return false;
        }

        // Kirim LOGIN
        $loginResp = self::imapCommand($socket, 1, 'LOGIN ' . self::imapQuote($email) . ' ' . self::imapQuote($password));
        if (strpos($loginResp, 'A001 OK') === false) {
            fclose($socket);
            // Coba ekstrak pesan error dari server
            if (strpos($loginResp, 'AUTHENTICATIONFAILED') !== false) {
                $errMsg = 'Autentikasi gagal. Pastikan email dan password benar.';
            } elseif (strpos($loginResp, 'NO') !== false) {
                $errMsg = 'Login ditolak server. ' . self::imapExtractError($loginResp);
            } else {
                $errMsg = 'Login gagal. ' . self::imapExtractError($loginResp);
            }
            return false;
        }

        return $socket;
    }

    /**
     * Kirim satu perintah IMAP dan kembalikan semua respons hingga tagged response
     */
    private static function imapCommand($socket, int $tagNum, string $cmd): string {
        $tag = 'A' . str_pad($tagNum, 3, '0', STR_PAD_LEFT);
        fwrite($socket, "{$tag} {$cmd}\r\n");
        $response = '';
        $deadline = time() + 20;
        while (time() < $deadline) {
            $line = self::imapReadLine($socket);
            if ($line === false) break;
            $response .= $line . "\n";
            if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) break;
        }
        return $response;
    }

    /**
     * Baca satu baris dari socket (menggunakan fgets untuk efisiensi)
     */
    private static function imapReadLine($socket): string|false {
        $line = @fgets($socket, 8192);
        if ($line === false) return false;
        $info = stream_get_meta_data($socket);
        if ($info['timed_out']) return false;
        return rtrim($line, "\r\n");
    }

    /**
     * Baca data IMAP multi-line (termasuk literal {N})
     */
    private static function imapReadFull($socket, int $tagNum): string {
        $tag     = 'A' . str_pad($tagNum, 3, '0', STR_PAD_LEFT);
        $result  = '';
        $deadline = time() + 30;

        while (time() < $deadline) {
            $line = self::imapReadLine($socket);
            if ($line === false) break;

            // Cek literal: {N}
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $result .= $line . "\n";
                $remaining = (int)$m[1];
                while ($remaining > 0 && time() < $deadline) {
                    $chunk = fread($socket, min($remaining, 8192));
                    if ($chunk === false || $chunk === '') {
                        $info = stream_get_meta_data($socket);
                        if ($info['timed_out'] || $info['eof']) break 2;
                        continue;
                    }
                    $result    .= $chunk;
                    $remaining -= strlen($chunk);
                }
                continue;
            }

            $result .= $line . "\n";
            if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) break;
        }
        return $result;
    }

    /**
     * Quote string untuk IMAP command
     */
    private static function imapQuote(string $s): string {
        if (strpbrk($s, " \t\r\n\"\\{}()[]%*") !== false) {
            return '"' . addcslashes($s, '"\\') . '"';
        }
        return '"' . $s . '"';
    }

    /**
     * Ekstrak pesan error dari respons IMAP
     */
    private static function imapExtractError(string $resp): string {
        if (preg_match('/\[([^\]]+)\]/', $resp, $m)) return $m[1];
        return '';
    }

    // ===== PUBLIC IMAP METHODS =====

    /**
     * Ambil daftar email di inbox (terbaru dulu)
     */
    public static function getInbox(string $email, string $password, int $limit = 20): array {
        $errMsg = '';
        $socket  = self::imapConnect($email, $password, $errMsg);
        if (!$socket) return ['error' => $errMsg ?: 'Gagal koneksi IMAP. Pastikan email dan password benar.'];

        $tag = 2;

        // SELECT INBOX
        $selResp = self::imapCommand($socket, $tag++, 'SELECT INBOX');
        if (strpos($selResp, 'A002 OK') === false) {
            fclose($socket);
            return ['error' => 'Gagal membuka INBOX.'];
        }

        // Dapatkan jumlah pesan
        preg_match('/\* (\d+) EXISTS/', $selResp, $m);
        $total = (int)($m[1] ?? 0);

        if ($total === 0) {
            self::imapCommand($socket, $tag++, 'LOGOUT');
            fclose($socket);
            return [];
        }

        // Ambil pesan dari akhir (terbaru)
        $start  = max(1, $total - $limit + 1);
        $end    = $total;

        // FETCH FLAGS, DATE, SIZE, ENVELOPE, UID
        $fetchTag = $tag++;
        fwrite($socket, 'A' . str_pad($fetchTag, 3, '0', STR_PAD_LEFT) . " FETCH {$start}:{$end} (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])\r\n");
        $fetchResp = self::imapReadFull($socket, $fetchTag);

        $emails = self::parseFetchList($fetchResp, $total);

        // Sort terbaru di atas
        usort($emails, fn($a, $b) => $b['uid'] - $a['uid']);

        self::imapCommand($socket, $tag++, 'LOGOUT');
        fclose($socket);
        return $emails;
    }

    /**
     * Parse hasil FETCH untuk list inbox
     * Menggunakan line-by-line parser yang lebih robust
     */
    private static function parseFetchList(string $resp, int $totalMsg): array {
        $emails = [];
        $lines  = explode("\n", str_replace("\r\n", "\n", $resp));
        $n      = count($lines);
        $i      = 0;

        while ($i < $n) {
            $line = rtrim($lines[$i]);

            // Cari baris "* N FETCH ("
            if (!preg_match('/^\* (\d+) FETCH \((.*)/s', $line, $m)) {
                $i++;
                continue;
            }

            $msgno     = (int)$m[1];
            $fetchData = $m[2] . "\n";
            $i++;

            // Kumpulkan semua baris untuk FETCH ini
            while ($i < $n) {
                $next = rtrim($lines[$i]);
                // Berhenti jika ketemu FETCH berikutnya atau tagged response
                if (preg_match('/^\* \d+ FETCH /', $next) || preg_match('/^A\d{3} (OK|NO|BAD) /i', $next)) {
                    break;
                }
                $fetchData .= $next . "\n";
                $i++;
            }

            // Parse field-field dari fetchData
            $uid  = 0;
            $seen = false;
            $size = 0;
            $date = '-';
            $from = 'Unknown';
            $subj = '(Tidak ada subjek)';

            if (preg_match('/\bUID (\d+)/i',          $fetchData, $x)) $uid  = (int)$x[1];
            if (preg_match('/RFC822\.SIZE (\d+)/i',   $fetchData, $x)) $size = (int)$x[1];
            if (preg_match('/FLAGS \(([^)]*)\)/i',    $fetchData, $x)) $seen = stripos($x[1], '\\Seen') !== false;
            if (preg_match('/INTERNALDATE "([^"]+)"/i', $fetchData, $x)) {
                $ts = strtotime($x[1]);
                if ($ts) $date = date('d/m/Y H:i', $ts);
            }

            // Ekstrak header block menggunakan literal size {N}
            if (preg_match('/BODY\[HEADER[^\]]*\] \{(\d+)\}\n([\s\S]*)/i', $fetchData, $x)) {
                $headerSize  = (int)$x[1];
                $headerBlock = substr($x[2], 0, $headerSize + 200); // sedikit lebih besar untuk keamanan

                if (preg_match('/^From:\s*(.+)/im',    $headerBlock, $h)) $from = self::decodeHeader(trim($h[1]));
                if (preg_match('/^Subject:\s*(.+)/im', $headerBlock, $h)) $subj = self::decodeHeader(trim($h[1]));
                if ($date === '-' && preg_match('/^Date:\s*(.+)/im', $headerBlock, $h)) {
                    $ts = strtotime(trim($h[1]));
                    if ($ts) $date = date('d/m/Y H:i', $ts);
                }
            }

            if ($uid > 0) {
                $emails[] = [
                    'uid'     => $uid,
                    'msgno'   => $msgno,
                    'subject' => $subj,
                    'from'    => $from,
                    'date'    => $date,
                    'seen'    => $seen,
                    'size'    => $size,
                ];
            }
        }

        return $emails;
    }

    /**
     * Baca isi satu email berdasarkan UID
     */
    public static function readMail(string $email, string $password, int $uid): array {
        $errMsg = '';
        $socket  = self::imapConnect($email, $password, $errMsg);
        if (!$socket) return ['error' => $errMsg ?: 'Gagal koneksi IMAP.'];

        $tag = 2;

        // SELECT INBOX
        $selResp = self::imapCommand($socket, $tag++, 'SELECT INBOX');
        if (strpos($selResp, 'A002 OK') === false) {
            fclose($socket);
            return ['error' => 'Gagal membuka INBOX.'];
        }

        // Fetch header
        $fetchHeaderTag = $tag++;
        fwrite($socket, 'A' . str_pad($fetchHeaderTag, 3, '0', STR_PAD_LEFT) . " UID FETCH {$uid} (FLAGS BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE)])\r\n");
        $headerResp = self::imapReadFull($socket, $fetchHeaderTag);

        $from    = 'Unknown';
        $to      = '-';
        $subject = '(Tidak ada subjek)';
        $date    = '-';
        $seen    = false;

        if (preg_match('/FLAGS \(([^)]*)\)/i', $headerResp, $m)) {
            $seen = stripos($m[1], '\\Seen') !== false;
        }

        // Extract header block menggunakan ukuran literal {N} — lebih robust
        $headerBlock = '';
        if (preg_match('/BODY\[HEADER[^\]]*\] \{(\d+)\}\r?\n/i', $headerResp, $m, PREG_OFFSET_CAPTURE)) {
            $size        = (int)$m[1][0];
            $blockStart  = $m[0][1] + strlen($m[0][0]);
            $headerBlock = substr($headerResp, $blockStart, $size + 200);
        } elseif (preg_match('/BODY\[HEADER[^\]]*\]\s*\r?\n([\s\S]+?)(?:\r?\n\)\r?\n|\r?\nA\d{3} )/i', $headerResp, $m)) {
            // Fallback jika server tidak kirim literal size
            $headerBlock = $m[1];
        }

        if ($headerBlock) {
            if (preg_match('/^From:\s*(.+)/im', $headerBlock, $m)) $from    = self::decodeHeader(trim($m[1]));
            if (preg_match('/^To:\s*(.+)/im',   $headerBlock, $m)) $to      = self::decodeHeader(trim($m[1]));
            if (preg_match('/^Subject:\s*(.+)/im', $headerBlock, $m)) $subject = self::decodeHeader(trim($m[1]));
            if (preg_match('/^Date:\s*(.+)/im',  $headerBlock, $m)) {
                $ts = strtotime(trim($m[1]));
                if ($ts) $date = date('d/m/Y H:i', $ts);
            }
        }

        // Fetch body (TEXT part)
        $fetchBodyTag = $tag++;
        fwrite($socket, 'A' . str_pad($fetchBodyTag, 3, '0', STR_PAD_LEFT) . " UID FETCH {$uid} BODY.PEEK[TEXT]\r\n");
        $bodyResp = self::imapReadFull($socket, $fetchBodyTag);
        $body     = self::extractBodyFromFetch($bodyResp);

        // Tandai sudah dibaca
        self::imapCommand($socket, $tag++, "UID STORE {$uid} +FLAGS (\\Seen)");

        self::imapCommand($socket, $tag++, 'LOGOUT');
        fclose($socket);

        return [
            'uid'     => $uid,
            'subject' => $subject,
            'from'    => $from,
            'to'      => $to,
            'date'    => $date,
            'seen'    => $seen,
            'body'    => $body,
        ];
    }

    /**
     * Extract body text dari respons FETCH
     */
    private static function extractBodyFromFetch(string $resp): string {
        // Cari literal block {N}
        if (preg_match('/BODY\[TEXT\] \{(\d+)\}\r?\n([\s\S]*)/i', $resp, $m)) {
            $size = (int)$m[1];
            $body = substr($m[2], 0, $size);
            return self::cleanBody($body);
        }
        // Cari quoted string
        if (preg_match('/BODY\[TEXT\] "((?:[^"\\\\]|\\\\.)*)"/i', $resp, $m)) {
            return self::cleanBody(stripslashes($m[2]));
        }
        // Fallback: ambil semua teks setelah FETCH response
        if (preg_match('/\* \d+ FETCH \(.+?\r?\n([\s\S]+?)(?=A\d{3} )/i', $resp, $m)) {
            return self::cleanBody($m[1]);
        }
        return '';
    }

    /**
     * Bersihkan body: decode encoding, hapus HTML tags
     */
    private static function cleanBody(string $body): string {
        // Coba decode base64 jika terlihat seperti base64
        $trimmed = preg_replace('/\s+/', '', $body);
        if (strlen($trimmed) > 20 && base64_encode(base64_decode($trimmed, true) ?: '') === $trimmed) {
            $decoded = base64_decode($trimmed);
            if ($decoded !== false) $body = $decoded;
        }

        // Decode quoted-printable
        if (strpos($body, '=') !== false && preg_match('/=[0-9A-F]{2}/i', $body)) {
            $qp = quoted_printable_decode($body);
            if (!empty(trim($qp))) $body = $qp;
        }

        // Konversi ke UTF-8
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'auto') ?: $body;
        }

        // Hapus HTML tags
        $body = strip_tags($body);

        // Decode HTML entities (misal &amp; → &, &lt; → <, &#x27; → ', dll.)
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($body);
    }

    /**
     * Hapus email berdasarkan UID
     */
    public static function deleteMail(string $email, string $password, int $uid): bool {
        $errMsg = '';
        $socket  = self::imapConnect($email, $password, $errMsg);
        if (!$socket) return false;

        $tag = 2;

        $selResp = self::imapCommand($socket, $tag++, 'SELECT INBOX');
        if (strpos($selResp, 'A002 OK') === false) {
            fclose($socket);
            return false;
        }

        self::imapCommand($socket, $tag++, "UID STORE {$uid} +FLAGS (\\Deleted)");
        self::imapCommand($socket, $tag++, 'EXPUNGE');
        self::imapCommand($socket, $tag++, 'LOGOUT');
        fclose($socket);
        return true;
    }

    // ===== IMAP HEADER DECODER =====

    public static function decodeHeader(string $str): string {
        if (strpos($str, '=?') === false) return trim($str);

        // Decode RFC 2047 encoded words: =?charset?encoding?text?=
        $result = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            function ($m) {
                $charset  = strtolower($m[1]);
                $encoding = strtolower($m[2]);
                $text     = $m[3];

                if ($encoding === 'b') {
                    $decoded = base64_decode($text);
                } else {
                    $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
                }

                if ($decoded === false) return $m[0];

                if ($charset !== 'utf-8') {
                    $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                    if ($converted !== false) $decoded = $converted;
                }

                return $decoded;
            },
            $str
        );

        return trim($result ?: $str);
    }

    // ===== SMTP (via raw socket — SSL/STARTTLS) =====

    /**
     * Kirim email via SMTP menggunakan raw socket (lebih reliable dari cURL SMTP)
     * Mencoba port 465 (smtps/implicit SSL) lalu fallback ke 587 (STARTTLS)
     */
    public static function sendMail(
        string $fromEmail,
        string $password,
        string $to,
        string $subject,
        string $body
    ): array {
        $host = self::$mailHost ?: MAIL_HOST;

        // Format pesan RFC 2822
        $date    = date('r');
        $msgId   = '<' . uniqid('', true) . '@' . substr(strrchr($fromEmail, '@'), 1) . '>';
        $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = "Date: {$date}\r\n"
                 . "To: <{$to}>\r\n"
                 . "From: <{$fromEmail}>\r\n"
                 . "Subject: {$encSubj}\r\n"
                 . "Message-ID: {$msgId}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "\r\n"
                 . chunk_split(base64_encode($body));

        // Coba port 465 (implicit SSL / smtps) dulu
        $result = self::socketSmtp($fromEmail, $password, $to, $message, $host, 465, true);
        if ($result['success']) return $result;

        // Fallback ke port 587 (STARTTLS)
        $result2 = self::socketSmtp($fromEmail, $password, $to, $message, $host, 587, false);
        if ($result2['success']) return $result2;

        // Fallback ke port 25
        $result3 = self::socketSmtp($fromEmail, $password, $to, $message, $host, 25, false);
        if ($result3['success']) return $result3;

        return ['success' => false, 'error' => $result['error'] . ' | ' . $result2['error']];
    }

    /**
     * Kirim via raw PHP socket SMTP
     * @param bool $implicitSsl true=port 465 (smtps), false=STARTTLS (port 587/25)
     */
    private static function socketSmtp(
        string $fromEmail,
        string $password,
        string $to,
        string $message,
        string $host,
        int $port,
        bool $implicitSsl
    ): array {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $errNo  = 0;
        $errStr = '';

        if ($implicitSsl) {
            // Port 465: koneksi langsung via SSL
            $socket = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errNo, $errStr, 20,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            // Port 587/25: koneksi biasa lalu STARTTLS
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errNo, $errStr, 20,
                STREAM_CLIENT_CONNECT,
                $context
            );
        }

        if (!$socket) {
            return ['success' => false, 'error' => "SMTP {$port}: Gagal koneksi — {$errStr}"];
        }

        stream_set_timeout($socket, 20);

        $read = function () use ($socket): string {
            $resp = '';
            $deadline = time() + 20;
            while (time() < $deadline) {
                $line = @fgets($socket, 1024);
                if ($line === false) break;
                $info = stream_get_meta_data($socket);
                if ($info['timed_out']) break;
                $resp .= $line;
                // Baris terakhir tidak ada tanda lanjut (format: "NNN text" tanpa dash)
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return trim($resp);
        };

        $write = function (string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        $expect = function (string $code) use ($read): array {
            $resp = $read();
            if (strncmp($resp, $code, strlen($code)) === 0) {
                return ['ok' => true, 'resp' => $resp];
            }
            return ['ok' => false, 'resp' => $resp];
        };

        // Baca greeting
        $greeting = $read();
        if (strncmp($greeting, '220', 3) !== 0) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP {$port}: Greeting buruk — {$greeting}"];
        }

        // EHLO — simpan respons untuk deteksi AUTH method
        $domain = substr(strrchr($fromEmail, '@'), 1) ?: 'localhost';
        $write("EHLO {$domain}");
        $ehloResp = $read();
        if (strncmp($ehloResp, '250', 3) !== 0) {
            // Coba HELO
            $write("HELO {$domain}");
            $r = $expect('250');
            if (!$r['ok']) {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP {$port}: EHLO/HELO ditolak — {$r['resp']}"];
            }
            $ehloResp = '';
        }

        // STARTTLS jika bukan implicit SSL
        if (!$implicitSsl) {
            $write('STARTTLS');
            $r = $expect('220');
            if (!$r['ok']) {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP {$port}: STARTTLS ditolak — {$r['resp']}"];
            }
            // Upgrade ke TLS
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP {$port}: Gagal upgrade TLS"];
            }
            // EHLO ulang setelah TLS — dapatkan daftar AUTH yang benar
            $write("EHLO {$domain}");
            $ehloResp = $read();
        }

        // Deteksi metode AUTH yang didukung dari respons EHLO
        $supportsPlain = stripos($ehloResp, 'AUTH PLAIN') !== false
                      || stripos($ehloResp, 'AUTH=PLAIN') !== false;
        $supportsLogin = stripos($ehloResp, 'AUTH LOGIN') !== false
                      || stripos($ehloResp, 'AUTH=LOGIN') !== false;

        // Tentukan urutan percobaan: PLAIN dulu (lebih umum di cPanel), lalu LOGIN
        $authMethods = [];
        if ($supportsPlain || (!$supportsPlain && !$supportsLogin)) $authMethods[] = 'PLAIN';
        if ($supportsLogin || (!$supportsPlain && !$supportsLogin)) $authMethods[] = 'LOGIN';

        $authOk    = false;
        $authError = '';
        foreach ($authMethods as $authMethod) {
            if ($authMethod === 'PLAIN') {
                // AUTH PLAIN: satu perintah dengan kredensial
                $plainCred = base64_encode("\0{$fromEmail}\0{$password}");
                $write("AUTH PLAIN {$plainCred}");
                $r = $expect('235');
                if ($r['ok']) { $authOk = true; break; }
                $authError = $r['resp'];
            } else {
                // AUTH LOGIN: username dan password dipisah
                $write('AUTH LOGIN');
                $r = $expect('334');
                if (!$r['ok']) { $authError = $r['resp']; continue; }
                $write(base64_encode($fromEmail));
                $r = $expect('334');
                if (!$r['ok']) { $authError = $r['resp']; continue; }
                $write(base64_encode($password));
                $r = $expect('235');
                if ($r['ok']) { $authOk = true; break; }
                $authError = $r['resp'];
            }
        }

        if (!$authOk) {
            fclose($socket);
            $hint = (strpos($authError, '535') !== false)
                ? ' (Password salah atau akun tidak ada di server ini)'
                : '';
            return ['success' => false, 'error' => "SMTP {$port}: Autentikasi gagal — {$authError}{$hint}"];
        }

        // MAIL FROM
        $write("MAIL FROM:<{$fromEmail}>");
        $r = $expect('250');
        if (!$r['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP {$port}: MAIL FROM ditolak — {$r['resp']}"];
        }

        // RCPT TO
        $write("RCPT TO:<{$to}>");
        $r = $expect('250');
        if (!$r['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP {$port}: RCPT TO ditolak — {$r['resp']}"];
        }

        // DATA
        $write('DATA');
        $r = $expect('354');
        if (!$r['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP {$port}: DATA ditolak — {$r['resp']}"];
        }

        // Kirim isi pesan (pastikan baris yang diawali titik di-escape)
        $escaped = preg_replace('/^\.$/m', '..', $message);
        fwrite($socket, $escaped . "\r\n.\r\n");

        $r = $expect('250');
        if (!$r['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP {$port}: Pesan ditolak server — {$r['resp']}"];
        }

        // QUIT
        $write('QUIT');
        fclose($socket);

        return ['success' => true];
    }

    // ===== TEST KONEKSI (untuk debug) =====

    /**
     * Test koneksi IMAP — return socket jika berhasil, false jika gagal
     * Caller bertanggung jawab menutup socket yang dikembalikan
     */
    public static function testImapConnect(string $email, string $password, string &$errMsg = '') {
        return self::imapConnect($email, $password, $errMsg);
    }

    /**
     * Test koneksi SMTP — return true jika auth berhasil, false jika gagal
     * @param bool $implicitSsl true=port 465, false=STARTTLS
     */
    public static function testSmtpConnect(
        string $email,
        string $password,
        string $host,
        int $port,
        bool $implicitSsl,
        string &$errMsg = ''
    ): bool {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $errNo = 0; $errStr = '';
        $scheme = $implicitSsl ? "ssl://" : "tcp://";
        $socket = @stream_socket_client("{$scheme}{$host}:{$port}", $errNo, $errStr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            $errMsg = "Gagal koneksi: {$errStr}";
            return false;
        }
        stream_set_timeout($socket, 15);

        $read = function () use ($socket): string {
            $resp = ''; $deadline = time() + 15;
            while (time() < $deadline) {
                $line = @fgets($socket, 1024);
                if ($line === false) break;
                $resp .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return trim($resp);
        };
        $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");

        $greeting = $read();
        if (strncmp($greeting, '220', 3) !== 0) {
            fclose($socket);
            $errMsg = "Greeting buruk: {$greeting}";
            return false;
        }

        $domain = substr(strrchr($email, '@'), 1) ?: 'localhost';
        $write("EHLO {$domain}");
        $ehloResp = $read();

        if (!$implicitSsl) {
            $write('STARTTLS');
            $startResp = $read();
            if (strncmp($startResp, '220', 3) !== 0) {
                fclose($socket);
                $errMsg = "STARTTLS ditolak: {$startResp}";
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                $errMsg = "Gagal upgrade TLS";
                return false;
            }
            $write("EHLO {$domain}");
            $ehloResp = $read();
        }

        // Coba AUTH PLAIN dulu, lalu AUTH LOGIN
        $plainCred = base64_encode("\0{$email}\0{$password}");
        $write("AUTH PLAIN {$plainCred}");
        $authResp = $read();
        if (strncmp($authResp, '235', 3) === 0) {
            $write('QUIT'); fclose($socket);
            return true;
        }

        // Coba AUTH LOGIN
        $write('AUTH LOGIN');
        $r1 = $read();
        if (strncmp($r1, '334', 3) === 0) {
            $write(base64_encode($email));
            $r2 = $read();
            if (strncmp($r2, '334', 3) === 0) {
                $write(base64_encode($password));
                $r3 = $read();
                if (strncmp($r3, '235', 3) === 0) {
                    $write('QUIT'); fclose($socket);
                    return true;
                }
                $authResp = $r3;
            } else {
                $authResp = $r2;
            }
        } else {
            $authResp = $r1;
        }

        fclose($socket);
        if (strpos($authResp, '535') !== false) {
            $errMsg = "Password salah atau akun tidak ada ({$authResp})";
        } else {
            $errMsg = $authResp;
        }
        return false;
    }

    // ===== HELPERS =====

    /**
     * Siapkan teks body email untuk ditampilkan di Telegram HTML mode.
     * - Bagian non-URL di-escape dengan htmlspecialchars
     * - URL dibungkus dalam <code>...</code> agar tidak di-auto-link Telegram
     *   (mencegah web preview dan masalah parsing HTML)
     */
    public static function prepareBodyForTelegram(string $plainText): string {
        $urlPattern = '/(?:https?|ftp):\/\/[^\s<>\[\]()"\',\x00-\x1F]+/i';

        $result = '';
        $offset = 0;
        $len    = strlen($plainText);

        // Temukan semua URL beserta posisinya
        if (!preg_match_all($urlPattern, $plainText, $matches, PREG_OFFSET_CAPTURE)) {
            // Tidak ada URL — langsung encode semua
            return htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        foreach ($matches[0] as [$url, $urlStart]) {
            // Encode teks sebelum URL
            if ($urlStart > $offset) {
                $result .= htmlspecialchars(
                    substr($plainText, $offset, $urlStart - $offset),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }

            // Potong tanda baca trailing yang bukan bagian URL
            $clean = rtrim($url, '.!?,;:)>');
            $trail = substr($url, strlen($clean));

            $result .= '<code>' . htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            if ($trail !== '') {
                $result .= htmlspecialchars($trail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $offset = $urlStart + strlen($url);
        }

        // Encode sisa teks setelah URL terakhir
        if ($offset < $len) {
            $result .= htmlspecialchars(
                substr($plainText, $offset),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        return $result;
    }

    public static function formatSize(int $bytes): string {
        if ($bytes < 1024)    return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    public static function truncate(string $text, int $max = 200): string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max) . '...';
    }

    public static function isValidEmail(string $email): bool {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
