<?php
/**
 * ================================================================
 *  DeepSeek AI Client
 *  Digunakan oleh webhook.php untuk fitur /ai
 * ================================================================
 */

/**
 * Konversi teks Markdown dari AI menjadi HTML yang didukung Telegram.
 *
 * Telegram HTML mendukung: <b>, <i>, <u>, <s>, <code>, <pre>, <a>
 * Pre + code mendukung: <pre><code class="language-xxx">...</code></pre>
 *
 * Urutan pemrosesan penting untuk menghindari konflik regex:
 * 1. Ekstrak code blocks (```...```) → placeholder
 * 2. Ekstrak inline code (`...`) → placeholder
 * 3. Escape HTML di teks biasa
 * 4. Konversi list dulu (sebelum italic) agar `*` di awal baris tidak terpicu italic
 * 5. Bold, italic, strikethrough, heading
 * 6. Restore placeholder
 */
function markdownToTelegramHtml(string $text): string {
    $placeholders = [];
    $idx = 0;

    // ── 1. Fenced code blocks: ```lang\ncode\n``` ──────────────────────────
    $text = preg_replace_callback(
        '/```(\w*)\r?\n?([\s\S]*?)```/s',
        function ($m) use (&$placeholders, &$idx) {
            $lang = strtolower(trim($m[1]));
            $code = htmlspecialchars(trim($m[2]), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $key  = "\x02BLOCK{$idx}\x03";

            // Bahasa yang didukung Telegram untuk syntax highlight
            $supported = [
                'php','python','javascript','js','typescript','ts',
                'java','kotlin','swift','c','cpp','c++','go','rust',
                'bash','shell','sh','html','css','sql','json','xml',
                'yaml','ruby','perl','r','dart','scala','lua','groovy',
            ];
            $langAttr = in_array($lang, $supported, true) ? ' class="language-' . $lang . '"' : '';

            $placeholders[$key] = "<pre><code{$langAttr}>{$code}</code></pre>";
            $idx++;
            return $key;
        },
        $text
    );

    // ── 2. Inline code: `code` ─────────────────────────────────────────────
    $text = preg_replace_callback(
        '/`([^`\r\n]+)`/',
        function ($m) use (&$placeholders, &$idx) {
            $code = htmlspecialchars($m[1], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $key  = "\x02INLINE{$idx}\x03";
            $placeholders[$key] = "<code>{$code}</code>";
            $idx++;
            return $key;
        },
        $text
    );

    // ── 3. Escape HTML di teks biasa (bukan placeholder) ──────────────────
    $delim  = '/(\x02(?:BLOCK|INLINE)\d+\x03)/';
    $parts  = preg_split($delim, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $text   = '';
    foreach ($parts as $part) {
        $text .= isset($placeholders[$part])
            ? $part
            : htmlspecialchars($part, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── 4. Unordered list: `* item` atau `- item` di awal baris ───────────
    //   (harus sebelum italic agar `*` tidak tertangkap sebagai italic)
    $text = preg_replace('/^[ \t]*[-*]\s+(.+)$/m', '• $1', $text);

    // ── 5. Bold: **text** atau __text__ ────────────────────────────────────
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $text);
    $text = preg_replace('/__(.+?)__/s',     '<b>$1</b>', $text);

    // ── 6. Italic: *text* (bukan ** atau list) atau _text_ ─────────────────
    //   Pastikan tidak menangkap tanda `*` yang tersisa dari **bold** atau list
    $text = preg_replace('/(?<!\*)\*(?!\*)(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<i>$1</i>', $text);
    $text = preg_replace('/(?<![_\w])_(?!_)(.+?)(?<!_)_(?![_\w])/s',    '<i>$1</i>', $text);

    // ── 7. Strikethrough: ~~text~~ ─────────────────────────────────────────
    $text = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $text);

    // ── 8. Heading: # Judul  →  <b>Judul</b> ──────────────────────────────
    $text = preg_replace('/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text);

    // ── 9. Blockquote: > teks  →  diremove tanda > saja ──────────────────
    $text = preg_replace('/^>\s?/m', '', $text);

    // ── 10. Restore placeholder ────────────────────────────────────────────
    $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

/**
 * Pecah teks panjang menjadi potongan aman untuk Telegram (maks $limit karakter).
 * Pemotongan di titik alami: setelah </pre>, setelah \n\n, atau setelah \n.
 */
function splitHtmlChunks(string $text, int $limit = 4000): array {
    if (mb_strlen($text) <= $limit) {
        return [$text];
    }

    $chunks = [];

    while (mb_strlen($text) > 0) {
        if (mb_strlen($text) <= $limit) {
            $chunks[] = trim($text);
            break;
        }

        $slice = mb_substr($text, 0, $limit);

        // ── Deteksi apakah kita di dalam blok <pre><code> ──────────────────
        $opens  = substr_count($slice, '<pre>');
        $closes = substr_count($slice, '</pre>');
        $insidePre = ($opens > $closes);

        $pos             = false;
        $needCloseReopen = false;
        $reopenTag       = '<pre><code>';

        if ($insidePre) {
            // Cari tag pembuka <pre><code...> terakhir yang belum ditutup,
            // agar bisa dibuka kembali di chunk berikutnya.
            if (preg_match_all('/<pre>(<code[^>]*>)/', $slice, $m)) {
                $lastCodeTag = end($m[1]);
                $reopenTag   = '<pre>' . $lastCodeTag;
            }

            // Potong di baris terakhir sebelum limit (supaya tidak putus di tengah baris kode)
            $p = mb_strrpos($slice, "\n");
            if ($p !== false && $p > 0) {
                $pos             = $p + 1;
                $needCloseReopen = true;
            } else {
                // Tidak ada newline, paksa potong di limit
                $pos             = $limit;
                $needCloseReopen = true;
            }
        } else {
            // ── Bukan di dalam pre block: cari titik potong alami ──────────

            // 1. Setelah </pre>
            $p = mb_strrpos($slice, '</pre>');
            if ($p !== false) {
                $pos = $p + mb_strlen('</pre>');
            }

            // 2. Setelah \n\n (paragraph break)
            if ($pos === false || $pos < (int)($limit * 0.4)) {
                $p2 = mb_strrpos($slice, "\n\n");
                if ($p2 !== false && $p2 > (int)($limit * 0.3)) {
                    $pos = $p2 + 2;
                }
            }

            // 3. Setelah \n (line break)
            if ($pos === false || $pos < (int)($limit * 0.3)) {
                $p3 = mb_strrpos($slice, "\n");
                if ($p3 !== false && $p3 > (int)($limit * 0.2)) {
                    $pos = $p3 + 1;
                }
            }

            // 4. Paksa potong di limit
            if ($pos === false || $pos === 0) {
                $pos = $limit;
            }
        }

        $chunk = trim(mb_substr($text, 0, $pos));

        if ($needCloseReopen) {
            // Tutup code block di akhir chunk ini
            $chunk .= "\n</code></pre>";
            // Buka kembali code block di awal sisa teks
            $text = $reopenTag . "\n" . ltrim(mb_substr($text, $pos));
        } else {
            $text = mb_substr($text, $pos);
        }

        if (trim($chunk) !== '') {
            $chunks[] = $chunk;
        }
    }

    return array_values(array_filter($chunks, fn($c) => trim($c) !== ''));
}

/**
 * Kirim satu chunk ke Telegram; fallback ke plain text jika HTML ditolak.
 */
function sendOneChunk(int $chatId, string $htmlChunk, array $extra = []): array {
    $params = array_merge([
        'chat_id'                  => $chatId,
        'text'                     => $htmlChunk,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra);

    $result = _telegramApi('sendMessage', $params);

    if (!($result['ok'] ?? false)) {
        $plainText = html_entity_decode(strip_tags($htmlChunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = trim(preg_replace("/\n{3,}/", "\n\n", $plainText));

        $params2 = array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $plainText ?: '(respons kosong)',
            'disable_web_page_preview' => true,
        ], $extra);
        $result = _telegramApi('sendMessage', $params2);
    }

    return $result;
}

/**
 * Kirim pesan AI ke Telegram.
 * - Otomatis memecah pesan panjang (> 4000 karakter) jadi beberapa pesan
 * - Fallback ke plain text jika HTML ditolak Telegram
 */
function sendAiReply(int $chatId, string $htmlText, array $extra = []): array {
    $chunks = splitHtmlChunks($htmlText, 4000);
    $result = ['ok' => true];

    foreach ($chunks as $i => $chunk) {
        // reply_to_message_id hanya untuk pesan pertama
        $chunkExtra = ($i === 0) ? $extra : array_diff_key($extra, ['reply_to_message_id' => 0]);
        $result     = sendOneChunk($chatId, $chunk, $chunkExtra);
    }

    return $result;
}

/**
 * Helper untuk memanggil Telegram API (digunakan oleh sendAiReply).
 * Pada runtime normal, webhook.php sudah define API_URL, jadi kita gunakan itu.
 */
function _telegramApi(string $method, array $params): array {
    if (!defined('API_URL')) {
        return ['ok' => false, 'description' => 'API_URL not defined'];
    }
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

// ============================================================
//  UUID v7 Generator (time-ordered, required by ai-seek API)
// ============================================================
function generateUuidV7(): string {
    // 48-bit Unix timestamp in milliseconds
    $ms    = (int)(microtime(true) * 1000);
    $tsHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);

    // 12-bit random for seq field
    $randA    = random_int(0, 0x0fff);
    $randAHex = str_pad(dechex($randA), 3, '0', STR_PAD_LEFT);

    // 64-bit random for the last two groups, with variant bits set (10xx xxxx)
    $randB    = random_bytes(8);
    $randB[0] = chr((ord($randB[0]) & 0x3f) | 0x80);
    $randBHex = bin2hex($randB);

    $p1 = substr($tsHex, 0, 8);   // 32 bits timestamp
    $p2 = substr($tsHex, 8, 4);   // 16 bits timestamp
    $p3 = '7' . $randAHex;        // version 7 + 12 bits random
    $p4 = substr($randBHex, 0, 4); // 16 bits (variant set)
    $p5 = substr($randBHex, 4, 12); // 48 bits random

    return "{$p1}-{$p2}-{$p3}-{$p4}-{$p5}";
}

// ============================================================
//  AI Image Generation Client (ai-seek.thebetter.ai)
// ============================================================
class AiImageGen {
    private string $baseUrl     = 'https://ai-seek.thebetter.ai/v4/chat/send';
    private string $accessToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJBUy1YTVZMRyIsImF1ZCI6ImFpLXNlZWsiLCJwcm92aWRlciI6InV1aWQiLCJpc3MiOiJhaS1zZWVrIiwiZXhwIjoyMDk1NjE3NjEwLCJpYXQiOjE3ODAyNTc2MTB9.DhYlou7QJPNgbfc-tvpozxiKnNLWsDQqLSKI0XCBbOzyjEeBxK7SDFiYzc0RDfp5Kn2eDOUzqvYdcvOU9Y_vylnQsk2SrpB-c_8nBPPfCfi8mqHyrOhB3HlVf7azNji_6yS09X6q07DCEML6hB1n6jSl8JmXBSn0rU5Rh2MXOTU';
    private string $deviceInfo  = 'appIdentifier=ai.chatbot.ask.chat.deep.seek.assistant.search.free;appVersion=2.7.2-26051464;deviceType=android;deviceCountry=ID;appCountry=id;local=id_ID;language=id;timezone=Asia/Jakarta;brand=xiaomi;model=Redmi%20Note%207;androidId=abe99601a1cd54d5';

    /**
     * Hasilkan gambar dari prompt teks.
     * Kembalikan URL gambar atau string error (diawali ❌).
     */
    public function generate(string $prompt): string {
        $sessionId     = generateUuidV7();
        $userMessageId = generateUuidV7();
        $aiMessageId   = generateUuidV7();

        $payload = json_encode([
            'sessionId'      => $sessionId,
            'userMessageId'  => $userMessageId,
            'aiMessageId'    => $aiMessageId,
            'model'          => 'image-generation-guru',
            'text'           => $prompt,
            'restrictedType' => 'PRO_USER',
            'sessionType'    => 'IMAGE',
        ]);

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'x-app-id: ai-seek',
                'x-device-info: ' . $this->deviceInfo,
                'accept: text/event-stream',
                'x-access-token: ' . $this->accessToken,
                'content-type: application/json',
                'user-agent: okhttp/4.12.0',
            ],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || !$response) {
            return '❌ Gagal menghubungi server AI gambar: ' . ($error ?: 'no response');
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return '❌ Token AI kedaluwarsa atau tidak valid. Hubungi administrator bot.';
        }

        // Parse SSE — cari event dengan field "images"
        $imageUrl = null;
        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if (strpos($line, 'data:') !== 0) continue;
            $json = trim(substr($line, 5));
            if ($json === '') continue;
            $obj = json_decode($json, true);
            if (!is_array($obj)) continue;
            if (!empty($obj['images']) && is_array($obj['images'])) {
                $imageUrl = $obj['images'][0];
                break;
            }
        }

        if (!$imageUrl) {
            return '❌ Tidak ada gambar yang dihasilkan. Coba prompt yang lebih spesifik.';
        }

        return $imageUrl;
    }

    /**
     * Ambil temporary S3 upload credentials dari API.
     * Return array data atau null jika gagal.
     */
    private function getImageUploadToken(string $ext = 'jpg'): ?array {
        $url = 'https://ai-seek.thebetter.ai/v4/token/generate_image_token?count=1&extension=' . urlencode($ext);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => [
                'x-app-id: ai-seek',
                'x-device-info: ' . $this->deviceInfo,
                'x-access-token: ' . $this->accessToken,
                'content-type: application/json',
                'user-agent: okhttp/4.12.0',
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        return $data['data'] ?? null;
    }

    /**
     * Upload binary gambar ke S3 menggunakan AWS Signature Version 4.
     * Return true jika berhasil (HTTP 2xx).
     */
    private function uploadToS3(
        string $body,
        string $s3Key,
        string $bucket,
        string $accessKeyId,
        string $secretKey,
        string $sessionToken,
        string $contentType = 'image/jpeg'
    ): bool {
        $region   = 'us-east-1';
        $service  = 's3';
        $host     = $bucket . '.s3-accelerate.amazonaws.com';
        $endpoint = 'https://' . $host . '/' . $s3Key;

        $now      = new DateTime('UTC');
        $datetime = $now->format('Ymd\THis\Z');
        $date     = $now->format('Ymd');
        $bodyHash = hash('sha256', $body);

        // Canonical headers — harus urut alfabet berdasarkan nama header
        $canonicalHeaders = "content-type:{$contentType}\n"
                          . "host:{$host}\n"
                          . "x-amz-content-sha256:{$bodyHash}\n"
                          . "x-amz-date:{$datetime}\n"
                          . "x-amz-security-token:{$sessionToken}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date;x-amz-security-token';

        // Canonical request
        $canonicalRequest = "PUT\n/{$s3Key}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$bodyHash}";

        // String to sign
        $scope        = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        // Signing key (HMAC chain)
        $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
        $kService = hash_hmac('sha256', $service,       $kRegion,            true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, "
                    . "SignedHeaders={$signedHeaders}, "
                    . "Signature={$signature}";

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'content-type: '         . $contentType,
                'host: '                 . $host,
                'x-amz-content-sha256: ' . $bodyHash,
                'x-amz-date: '           . $datetime,
                'x-amz-security-token: ' . $sessionToken,
                'authorization: '        . $authHeader,
                'user-agent: Dart/3.8 (dart:io)',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code >= 200 && $code < 300);
    }

    /**
     * Edit gambar menggunakan AI.
     * Upload foto asli ke S3, lalu kirim ke AI dengan instruksi.
     *
     * @param string $imageBinary  Data binary foto asli
     * @param string $instruction  Instruksi edit untuk AI
     * @return string URL gambar hasil, atau pesan error (diawali ❌)
     */
    public function editImage(string $imageBinary, string $instruction): string {
        // Langkah 1: Dapatkan S3 upload credentials
        $tokenData = $this->getImageUploadToken('jpg');
        if (!$tokenData || empty($tokenData['imageS3KeyList'])) {
            return '❌ Gagal mendapatkan token upload gambar.';
        }
        $s3Key        = $tokenData['imageS3KeyList'][0];
        $bucket       = $tokenData['S3bucket'];
        $accessKeyId  = $tokenData['accessKeyId'];
        $secretKey    = $tokenData['secretAccessKey'];
        $sessionToken = $tokenData['sessionToken'];

        // Langkah 2: Upload gambar ke S3
        $uploaded = $this->uploadToS3($imageBinary, $s3Key, $bucket, $accessKeyId, $secretKey, $sessionToken);
        if (!$uploaded) {
            return '❌ Gagal mengupload gambar ke server.';
        }

        // Langkah 3: Kirim request AI dengan imageS3Keys
        $sessionId     = generateUuidV7();
        $userMessageId = generateUuidV7();
        $aiMessageId   = generateUuidV7();

        $payload = json_encode([
            'sessionId'      => $sessionId,
            'userMessageId'  => $userMessageId,
            'aiMessageId'    => $aiMessageId,
            'model'          => 'image-generation-guru',
            'text'           => $instruction,
            'restrictedType' => 'PRO_USER',
            'sessionType'    => 'IMAGE',
            'imageS3Keys'    => [$s3Key],
        ]);

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'x-app-id: ai-seek',
                'x-device-info: ' . $this->deviceInfo,
                'accept: text/event-stream',
                'x-access-token: ' . $this->accessToken,
                'content-type: application/json',
                'user-agent: okhttp/4.12.0',
            ],
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || !$response) {
            return '❌ Gagal menghubungi server AI: ' . ($error ?: 'no response');
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return '❌ Token AI kedaluwarsa atau tidak valid. Hubungi administrator bot.';
        }

        // Parse SSE — cari event dengan field "images"
        $imageUrl = null;
        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if (strpos($line, 'data:') !== 0) continue;
            $json = trim(substr($line, 5));
            if ($json === '') continue;
            $obj = json_decode($json, true);
            if (!is_array($obj)) continue;
            if (!empty($obj['images']) && is_array($obj['images'])) {
                $imageUrl = $obj['images'][0];
                break;
            }
        }

        if (!$imageUrl) {
            return '❌ AI tidak menghasilkan gambar. Coba instruksi yang lebih jelas.';
        }
        return $imageUrl;
    }

    /**
     * Unduh gambar dari URL dan kembalikan binary-nya.
     * Return null jika gagal.
     */
    public function downloadImage(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'okhttp/4.12.0',
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($data && $code === 200) ? $data : null;
    }
}

// ============================================================
//  DeepSeek AI Client
// ============================================================
class DeepSeek {
    private $internalKey = "NiIsImtpZCI6I56";
    private $baseUrl     = 'https://deepseekv2-qbvg2hl3qq-uc.a.run.app';
    private $keyUrl      = 'https://rotatingkey-qbvg2hl3qq-uc.a.run.app';

    public function getRotatingKey(): string {
        $ch = curl_init($this->keyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: Android'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('Gagal mengambil rotatingKey: ' . curl_error($ch));
        }
        curl_close($ch);
        $data = json_decode($response, true);
        if (!isset($data['rotatingKey'])) {
            throw new \Exception('RotatingKey tidak ditemukan dalam respon');
        }
        return $data['rotatingKey'];
    }

    public function encrypt(string $text, string $key): array {
        $iv        = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($text, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return [
            'cipherText' => base64_encode($encrypted),
            'iv'         => base64_encode($iv),
        ];
    }

    /**
     * Kirim pesan ke AI.
     *
     * @param string $userPrompt  Pesan user terbaru
     * @param array  $history     Riwayat percakapan: [ ['role'=>'user','content'=>'...'], ... ]
     * @param string $model       Model yang digunakan
     * @return string             Teks balasan AI (sudah diformat HTML Telegram), atau pesan error
     */
    public function chat(string $userPrompt, array $history = [], string $model = 'deepseek-chat'): string {
        try {
            $secretKey        = $this->getRotatingKey();
            $encryptionResult = $this->encrypt($this->internalKey, $secretKey);
            $bearerToken      = trim($encryptionResult['cipherText']);
            $ivBase64         = $encryptionResult['iv'];

            $messages   = $history;
            $messages[] = ['role' => 'user', 'content' => $userPrompt];

            $payload = [
                'data'      => $userPrompt,
                'iv'        => $ivBase64 . "\n",
                'messages'  => $messages,
                'model'     => $model,
                'secretKey' => $secretKey,
            ];

            $ch = curl_init($this->baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $bearerToken,
                    'Content-Type: application/json',
                    'User-Agent: okhttp/4.12.0',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 90,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$response) {
                return '❌ Tidak ada respons dari server AI.';
            }

            $data = json_decode($response, true);

            if (isset($data['error'])) {
                return '❌ Error AI: ' . (is_string($data['error']) ? $data['error'] : json_encode($data['error']));
            }

            $content = $data['data']['choices'][0]['message']['content'] ?? null;
            if ($content === null) {
                return '❌ Respons AI tidak valid (HTTP ' . $httpCode . ').';
            }

            // Konversi Markdown ke HTML Telegram
            return markdownToTelegramHtml($content);

        } catch (\Exception $e) {
            return '❌ ' . $e->getMessage();
        }
    }
}
