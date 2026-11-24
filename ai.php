<?php
/**
 * ================================================================
 *  AI Client (Gemini API + DeepSeek API)
 *  Digunakan oleh webhook.php untuk /ai, /translate, /detect,
 *  /summarize, /imagine, /edit, serta pemilihan default di panel SA.
 * ================================================================
 */

/**
 * Konversi teks Markdown dari AI menjadi HTML yang didukung Telegram.
 *
 * Telegram HTML mendukung: <b>, <i>, <u>, <s>, <code>, <pre>, <a>
 * Pre + code mendukung: <pre><code class="language-xxx">...</code></pre>
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
    $text = preg_replace('/^[ \t]*[-*]\s+(.+)$/m', '• $1', $text);

    // ── 5. Bold: **text** atau __text__ ────────────────────────────────────
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $text);
    $text = preg_replace('/__(.+?)__/s',     '<b>$1</b>', $text);

    // ── 6. Italic: *text* atau _text_ ──────────────────────────────────────
    $text = preg_replace('/(?<!\*)\*(?!\*)(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<i>$1</i>', $text);
    $text = preg_replace('/(?<![_\w])_(?!_)(.+?)(?<!_)_(?![_\w])/s',    '<i>$1</i>', $text);

    // ── 7. Strikethrough: ~~text~~ ─────────────────────────────────────────
    $text = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $text);

    // ── 8. Heading: # Judul  →  <b>Judul</b> ──────────────────────────────
    $text = preg_replace('/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text);

    // ── 9. Blockquote: > teks ──────────────────────────────────────────────
    $text = preg_replace('/^>\s?/m', '', $text);

    // ── 10. Restore placeholder ────────────────────────────────────────────
    $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

/**
 * Pecah teks panjang menjadi potongan aman untuk Telegram (maks $limit karakter).
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

        $opens     = substr_count($slice, '<pre>');
        $closes    = substr_count($slice, '</pre>');
        $insidePre = ($opens > $closes);

        $pos             = false;
        $needCloseReopen = false;
        $reopenTag       = '<pre><code>';

        if ($insidePre) {
            if (preg_match_all('/<pre>(<code[^>]*>)/', $slice, $m)) {
                $lastCodeTag = end($m[1]);
                $reopenTag   = '<pre>' . $lastCodeTag;
            }

            $p = mb_strrpos($slice, "\n");
            if ($p !== false && $p > 0) {
                $pos             = $p + 1;
                $needCloseReopen = true;
            } else {
                $pos             = $limit;
                $needCloseReopen = true;
            }
        } else {
            $p = mb_strrpos($slice, '</pre>');
            if ($p !== false) {
                $pos = $p + mb_strlen('</pre>');
            }

            if ($pos === false || $pos < (int)($limit * 0.4)) {
                $p2 = mb_strrpos($slice, "\n\n");
                if ($p2 !== false && $p2 > (int)($limit * 0.3)) {
                    $pos = $p2 + 2;
                }
            }

            if ($pos === false || $pos < (int)($limit * 0.3)) {
                $p3 = mb_strrpos($slice, "\n");
                if ($p3 !== false && $p3 > (int)($limit * 0.2)) {
                    $pos = $p3 + 1;
                }
            }

            if ($pos === false || $pos === 0) {
                $pos = $limit;
            }
        }

        $chunk = trim(mb_substr($text, 0, $pos));

        if ($needCloseReopen) {
            $chunk .= "\n</code></pre>";
            $text   = $reopenTag . "\n" . ltrim(mb_substr($text, $pos));
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
 */
function sendAiReply(int $chatId, string $htmlText, array $extra = []): array {
    $chunks = splitHtmlChunks($htmlText, 4000);
    $result = ['ok' => true];

    foreach ($chunks as $i => $chunk) {
        $chunkExtra = ($i === 0) ? $extra : array_diff_key($extra, ['reply_to_message_id' => 0]);
        $result     = sendOneChunk($chatId, $chunk, $chunkExtra);
    }

    return $result;
}

/**
 * Helper untuk memanggil Telegram API (digunakan oleh sendAiReply).
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
//  UUID v7 Generator
// ============================================================
function generateUuidV7(): string {
    $ms    = (int)(microtime(true) * 1000);
    $tsHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);

    $randA    = random_int(0, 0x0fff);
    $randAHex = str_pad(dechex($randA), 3, '0', STR_PAD_LEFT);

    $randB    = random_bytes(8);
    $randB[0] = chr((ord($randB[0]) & 0x3f) | 0x80);
    $randBHex = bin2hex($randB);

    $p1 = substr($tsHex, 0, 8);
    $p2 = substr($tsHex, 8, 4);
    $p3 = '7' . $randAHex;
    $p4 = substr($randBHex, 0, 4);
    $p5 = substr($randBHex, 4, 12);

    return "{$p1}-{$p2}-{$p3}-{$p4}-{$p5}";
}

// ============================================================
//  AI Model Registry & Configuration
// ============================================================
class AiConfig {
    public const DEFAULT_CHAT_MODEL  = 'gemini-4-hydrogen';
    public const DEFAULT_IMAGE_MODEL = 'gemini-3.1-flash-lite-image';

    public const CHAT_MODELS = [
        'gemini-4-hydrogen' => [
            'label'    => '✨ Gemini 4 Hydrogen',
            'short'    => 'Gemini 4 Hydrogen',
            'provider' => 'gemini',
            'desc'     => 'Standar & seimbang (Rekomendasi)',
        ],
        'gemini-3.1-flash-lite' => [
            'label'    => '⚡ Gemini 3.1 Flash Lite',
            'short'    => 'Gemini Flash Lite',
            'provider' => 'gemini',
            'desc'     => 'Respons sangat cepat & ringan',
        ],
        'gemini-4-neon' => [
            'label'    => '🧠 Gemini 4 Neon',
            'short'    => 'Gemini 4 Neon',
            'provider' => 'gemini',
            'desc'     => 'Penalaran kompleks (Paid API Key)',
        ],
        'gemini-4-carbon' => [
            'label'    => '🔬 Gemini 4 Carbon',
            'short'    => 'Gemini 4 Carbon',
            'provider' => 'gemini',
            'desc'     => 'Penalaran mendalam (Paid API Key)',
        ],
        'deepseek-chat' => [
            'label'    => '🐋 DeepSeek Chat',
            'short'    => 'DeepSeek',
            'provider' => 'deepseek',
            'desc'     => 'Official DeepSeek API (DEEPSEEK_API_KEY)',
        ],
    ];

    public const IMAGE_MODELS = [
        'gemini-3.1-flash-lite-image' => [
            'label'    => '🍌 Gemini 3.1 Flash Lite Image',
            'short'    => 'Gemini Flash Image',
            'provider' => 'gemini',
            'desc'     => 'Cepat & serbaguna (Default)',
        ],
        'gemini-nano-banana-2.1' => [
            'label'    => '🖼️ Gemini Nano Banana 2.1',
            'short'    => 'Nano Banana 2.1',
            'provider' => 'gemini',
            'desc'     => 'Kualitas tinggi (1K/2K/4K)',
        ],
        'gemini-3-pro-image' => [
            'label'    => '🎨 Gemini 3 Pro Image',
            'short'    => 'Gemini 3 Pro Image',
            'provider' => 'gemini',
            'desc'     => 'Detail artistik Pro',
        ],
    ];

    public static function resolveChatModel(?string $modelId): string {
        $id = trim((string)$modelId);
        return isset(self::CHAT_MODELS[$id]) ? $id : self::DEFAULT_CHAT_MODEL;
    }

    public static function resolveImageModel(?string $modelId): string {
        $id = trim((string)$modelId);
        return isset(self::IMAGE_MODELS[$id]) ? $id : self::DEFAULT_IMAGE_MODEL;
    }

    public static function getChatModelInfo(?string $modelId): array {
        $resolved = self::resolveChatModel($modelId);
        return self::CHAT_MODELS[$resolved] + ['id' => $resolved];
    }

    public static function getImageModelInfo(?string $modelId): array {
        $resolved = self::resolveImageModel($modelId);
        return self::IMAGE_MODELS[$resolved] + ['id' => $resolved];
    }

    public static function getGeminiApiKey(): string {
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
            return (string)GEMINI_API_KEY;
        }
        return (string)(getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ''));
    }

    public static function getDeepSeekApiKey(): string {
        if (defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY !== '') {
            return (string)DEEPSEEK_API_KEY;
        }
        return (string)(getenv('DEEPSEEK_API_KEY') ?: ($_ENV['DEEPSEEK_API_KEY'] ?? ''));
    }

    public static function isGeminiKeyConfigured(): bool {
        return self::getGeminiApiKey() !== '';
    }

    public static function isDeepSeekKeyConfigured(): bool {
        return self::getDeepSeekApiKey() !== '';
    }
}

// ============================================================
//  Gemini AI Client (REST v1beta via Pure PHP cURL)
// ============================================================
class GeminiClient {
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey !== null ? $apiKey : AiConfig::getGeminiApiKey();
    }

    /**
     * Panggil endpoint generateContent dengan retry exponential backoff untuk error transient.
     */
    private function request(string $model, array $payload, int $timeout = 90): array {
        if ($this->apiKey === '') {
            return [
                'ok'       => false,
                'httpCode' => 0,
                'error'    => 'GEMINI_API_KEY belum diset di environment server.',
            ];
        }

        $url  = $this->baseUrl . rawurlencode($model) . ':generateContent';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $maxAttempts = 3;
        $delaySec    = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                    'User-Agent: aistudio-build',
                ],
            ]);
            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $curlErr  = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) {
                if ($attempt < $maxAttempts) {
                    sleep($delaySec);
                    $delaySec *= 2;
                    continue;
                }
                return [
                    'ok'       => false,
                    'httpCode' => 0,
                    'error'    => 'Gagal menghubungi Gemini API: ' . $curlErr,
                ];
            }

            $data = json_decode($response ?: '{}', true);
            if (!is_array($data)) {
                return [
                    'ok'       => false,
                    'httpCode' => $httpCode,
                    'error'    => "Respons tidak valid dari Gemini API (HTTP {$httpCode}).",
                ];
            }

            if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
                return ['ok' => true, 'httpCode' => $httpCode, 'data' => $data];
            }

            // Retry hanya pada rate limit / service unavailable sementara
            if (($httpCode === 429 || $httpCode === 503) && $attempt < $maxAttempts) {
                sleep($delaySec);
                $delaySec *= 2;
                continue;
            }

            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            $status = $data['error']['status'] ?? '';

            if ($httpCode === 403 || $status === 'PERMISSION_DENIED') {
                $errMsg = 'API key Gemini tidak memiliki izin atau tidak valid (403 PERMISSION_DENIED).';
            } elseif ($httpCode === 404 || $status === 'NOT_FOUND') {
                $errMsg = "Model '{$model}' tidak ditemukan atau belum didukung (404 NOT_FOUND).";
            } elseif ($httpCode === 429 || $status === 'RESOURCE_EXHAUSTED') {
                $errMsg = 'Kuota / rate limit Gemini API tercapai (429 RESOURCE_EXHAUSTED). Pastikan menggunakan API key dengan kuota atau billing aktif.';
            }

            return [
                'ok'       => false,
                'httpCode' => $httpCode,
                'error'    => $errMsg,
            ];
        }

        return ['ok' => false, 'httpCode' => 0, 'error' => 'Gagal memanggil Gemini API.'];
    }

    /**
     * Chat multi-turn dengan Gemini.
     */
    public function chat(
        string $userPrompt,
        array $history = [],
        string $model = AiConfig::DEFAULT_CHAT_MODEL,
        string $systemInstruction = ''
    ): string {
        $model = AiConfig::resolveChatModel($model);
        if (AiConfig::CHAT_MODELS[$model]['provider'] !== 'gemini') {
            $model = AiConfig::DEFAULT_CHAT_MODEL;
        }

        $contents = [];
        foreach ($history as $item) {
            $text = trim((string)($item['content'] ?? ''));
            if ($text === '' || strpos($text, '❌') === 0) {
                continue;
            }
            $role = (($item['role'] ?? 'user') === 'assistant') ? 'model' : 'user';
            if ($role === 'model' && strpos($text, '🤖 ') === 0) {
                $text = substr($text, strlen('🤖 '));
            }
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $text]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $userPrompt]],
        ];

        $payload = ['contents' => $contents];
        if (trim($systemInstruction) !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => trim($systemInstruction)]],
            ];
        }

        $res = $this->request($model, $payload, 90);
        if (!$res['ok']) {
            return '❌ ' . $res['error'];
        }

        $parts = $res['data']['candidates'][0]['content']['parts'] ?? [];
        $out   = '';
        foreach ($parts as $part) {
            if (!empty($part['thought'])) {
                continue;
            }
            if (isset($part['text'])) {
                $out .= $part['text'];
            }
        }

        if (trim($out) === '') {
            $finishReason = $res['data']['candidates'][0]['finishReason'] ?? '';
            if ($finishReason === 'SAFETY') {
                return '❌ Permintaan diblokir oleh filter keamanan AI.';
            }
            return '❌ AI tidak mengembalikan jawaban teks.';
        }

        return markdownToTelegramHtml($out);
    }

    /**
     * Generate gambar baru dari teks prompt menggunakan Gemini Image Models.
     * Return: ['ok' => true, 'data' => binary, 'mime' => string] atau ['ok' => false, 'error' => string]
     */
    public function generateImage(string $prompt, string $model = AiConfig::DEFAULT_IMAGE_MODEL): array {
        $model   = AiConfig::resolveImageModel($model);
        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
        ];

        if ($model === 'gemini-nano-banana-2.1' || $model === 'gemini-3-pro-image') {
            $payload['generationConfig'] = [
                'imageConfig' => [
                    'aspectRatio' => '1:1',
                    'imageSize'   => '1K',
                ],
            ];
        }

        $res = $this->request($model, $payload, 120);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => '❌ ' . $res['error']];
        }

        return $this->extractImageFromResponse($res['data'], '❌ Tidak ada gambar yang dihasilkan. Coba prompt yang lebih spesifik.');
    }

    /**
     * Edit gambar dari binary gambar + instruksi teks.
     * Return: ['ok' => true, 'data' => binary, 'mime' => string] atau ['ok' => false, 'error' => string]
     */
    public function editImage(
        string $imageBinary,
        string $instruction,
        string $model = AiConfig::DEFAULT_IMAGE_MODEL,
        string $mimeType = ''
    ): array {
        $model = AiConfig::resolveImageModel($model);
        if ($mimeType === '') {
            $mimeType = self::detectImageMime($imageBinary);
        }

        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data'     => base64_encode($imageBinary),
                        ],
                    ],
                    [
                        'text' => $instruction,
                    ],
                ],
            ]],
        ];

        $res = $this->request($model, $payload, 120);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => '❌ ' . $res['error']];
        }

        return $this->extractImageFromResponse($res['data'], '❌ AI tidak menghasilkan gambar hasil edit. Coba instruksi yang lebih jelas.');
    }

    private function extractImageFromResponse(array $data, string $emptyErr): array {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $textFallback = '';
        foreach ($parts as $part) {
            if (!empty($part['inlineData']['data'])) {
                $raw = base64_decode($part['inlineData']['data'], true);
                if ($raw !== false && $raw !== '') {
                    return [
                        'ok'   => true,
                        'data' => $raw,
                        'mime' => $part['inlineData']['mimeType'] ?? 'image/png',
                    ];
                }
            } elseif (!empty($part['text']) && empty($part['thought'])) {
                $textFallback .= ' ' . trim($part['text']);
            }
        }

        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'SAFETY') {
            return ['ok' => false, 'error' => '❌ Permintaan gambar diblokir oleh filter keamanan AI.'];
        }
        if (trim($textFallback) !== '') {
            return ['ok' => false, 'error' => '❌ ' . mb_substr(trim($textFallback), 0, 300)];
        }
        return ['ok' => false, 'error' => $emptyErr];
    }

    public static function detectImageMime(string $binary): string {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_buffer($finfo, $binary);
                @finfo_close($finfo);
                if (is_string($mime) && strpos($mime, 'image/') === 0) {
                    return $mime;
                }
            }
        }
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($binary);
            if (!empty($info['mime'])) {
                return $info['mime'];
            }
        }
        return 'image/jpeg';
    }
}

// ============================================================
//  DeepSeek Official API Client
// ============================================================
class DeepSeek {
    private string $apiKey;
    private string $apiUrl;

    public function __construct(?string $apiKey = null, ?string $apiUrl = null) {
        $this->apiKey = $apiKey !== null ? $apiKey : AiConfig::getDeepSeekApiKey();
        $this->apiUrl = $apiUrl !== null
            ? $apiUrl
            : (defined('DEEPSEEK_API_URL') ? DEEPSEEK_API_URL : 'https://api.deepseek.com/v1/chat/completions');
    }

    /**
     * Kirim pesan ke DeepSeek Official API (atau delegate ke AiService jika model bukan deepseek).
     */
    public function chat(string $userPrompt, array $history = [], string $model = 'deepseek-chat', string $systemInstruction = ''): string {
        // Jika model adalah Gemini, teruskan ke GeminiClient
        if (isset(AiConfig::CHAT_MODELS[$model]) && AiConfig::CHAT_MODELS[$model]['provider'] === 'gemini') {
            return (new GeminiClient())->chat($userPrompt, $history, $model, $systemInstruction);
        }

        if ($this->apiKey === '') {
            return '❌ DEEPSEEK_API_KEY belum diset di environment server. Pilih model Gemini di Panel Superadmin → Pengaturan AI, atau set DEEPSEEK_API_KEY.';
        }

        $messages = [];
        if (trim($systemInstruction) !== '') {
            $messages[] = ['role' => 'system', 'content' => trim($systemInstruction)];
        }
        foreach ($history as $item) {
            $content = trim((string)($item['content'] ?? ''));
            if ($content === '' || strpos($content, '❌') === 0) {
                continue;
            }
            $role = (($item['role'] ?? 'user') === 'assistant') ? 'assistant' : 'user';
            if ($role === 'assistant' && strpos($content, '🤖 ') === 0) {
                $content = substr($content, strlen('🤖 '));
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $payload = json_encode([
            'model'    => 'deepseek-chat',
            'messages' => $messages,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 90,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || !$response) {
            return '❌ Gagal menghubungi DeepSeek API: ' . ($curlErr ?: 'no response');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return "❌ Respons DeepSeek tidak valid (HTTP {$httpCode}).";
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : (string)$data['error'];
            return '❌ Error DeepSeek API: ' . $msg;
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null || trim((string)$content) === '') {
            return "❌ Respons DeepSeek kosong (HTTP {$httpCode}).";
        }

        return markdownToTelegramHtml((string)$content);
    }
}

// ============================================================
//  AI Image Generation & Download Wrapper (Gemini Powered)
// ============================================================
class AiImageGen {
    /**
     * Generate gambar sebagai binary bytes + mime type.
     */
    public function generateBinary(string $prompt, ?string $model = null): array {
        $resolved = AiConfig::resolveImageModel($model);
        return (new GeminiClient())->generateImage($prompt, $resolved);
    }

    /**
     * Edit gambar dari binary foto asli + instruksi.
     */
    public function editImageBinary(string $imageBinary, string $instruction, ?string $model = null, string $mimeType = ''): array {
        $resolved = AiConfig::resolveImageModel($model);
        return (new GeminiClient())->editImage($imageBinary, $instruction, $resolved, $mimeType);
    }

    /**
     * Unduh binary gambar dari URL (digunakan untuk ambil foto dari Telegram File API).
     */
    public function downloadImage(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'xnoxsguard-bot/3.1',
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($data !== false && $data !== '' && $code >= 200 && $code < 300) ? $data : null;
    }
}

// ============================================================
//  Unified AI Service Facade (reads SA Default Settings)
// ============================================================
class AiService {
    public static function getActiveChatModel(?Database $db = null): string {
        $db = $db ?: (class_exists('Database') ? Database::getInstance() : null);
        $raw = $db ? $db->getBotSetting('default_ai_chat', AiConfig::DEFAULT_CHAT_MODEL) : AiConfig::DEFAULT_CHAT_MODEL;
        return AiConfig::resolveChatModel($raw);
    }

    public static function getActiveImageModel(?Database $db = null): string {
        $db = $db ?: (class_exists('Database') ? Database::getInstance() : null);
        $raw = $db ? $db->getBotSetting('default_ai_image', AiConfig::DEFAULT_IMAGE_MODEL) : AiConfig::DEFAULT_IMAGE_MODEL;
        return AiConfig::resolveImageModel($raw);
    }

    public static function chat(
        string $userPrompt,
        array $history = [],
        ?string $model = null,
        string $systemInstruction = ''
    ): string {
        $resolved = AiConfig::resolveChatModel($model ?? self::getActiveChatModel());
        $info     = AiConfig::CHAT_MODELS[$resolved];

        if ($info['provider'] === 'deepseek') {
            return (new DeepSeek())->chat($userPrompt, $history, $resolved, $systemInstruction);
        }
        return (new GeminiClient())->chat($userPrompt, $history, $resolved, $systemInstruction);
    }

    public static function generateImage(string $prompt, ?string $model = null): array {
        $resolved = AiConfig::resolveImageModel($model ?? self::getActiveImageModel());
        return (new AiImageGen())->generateBinary($prompt, $resolved);
    }

    public static function editImage(string $imageBinary, string $instruction, ?string $model = null, string $mimeType = ''): array {
        $resolved = AiConfig::resolveImageModel($model ?? self::getActiveImageModel());
        return (new AiImageGen())->editImageBinary($imageBinary, $instruction, $resolved, $mimeType);
    }
}
