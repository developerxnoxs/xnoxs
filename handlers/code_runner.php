<?php
/**
 * ================================================================
 *  Code Runner — Judge0 CE API Integration
 *  Eksekusi kode multi-bahasa via /run
 *
 *  API Base : https://ce.judge0.com
 *  Endpoint : POST /submissions?base64_encoded=false&wait=true
 *  No API key required for ce.judge0.com
 * ================================================================
 */

class CodeRunner {

    const API_URL = 'https://ce.judge0.com/submissions?base64_encoded=false&wait=true';

    /**
     * Map alias bahasa => [language_id, label]
     * ID berdasarkan https://ce.judge0.com/languages/
     */
    private static array $languages = [
        // Python
        'python'     => [109, 'Python 3.13'],
        'python3'    => [109, 'Python 3.13'],
        'py'         => [109, 'Python 3.13'],
        'py3'        => [109, 'Python 3.13'],
        'python2'    => [70,  'Python 2.7'],
        'py2'        => [70,  'Python 2.7'],

        // JavaScript / Node.js
        'javascript' => [102, 'JavaScript (Node 22)'],
        'js'         => [102, 'JavaScript (Node 22)'],
        'node'       => [102, 'JavaScript (Node 22)'],
        'nodejs'     => [102, 'JavaScript (Node 22)'],

        // TypeScript
        'typescript' => [101, 'TypeScript 5.6'],
        'ts'         => [101, 'TypeScript 5.6'],

        // Java
        'java'       => [91,  'Java 17'],

        // Kotlin
        'kotlin'     => [111, 'Kotlin 2.1'],
        'kt'         => [111, 'Kotlin 2.1'],

        // Scala
        'scala'      => [81,  'Scala 2.13'],

        // C
        'c'          => [103, 'C (GCC 14)'],
        'gcc'        => [103, 'C (GCC 14)'],

        // C++
        'c++'        => [105, 'C++ (GCC 14)'],
        'cpp'        => [105, 'C++ (GCC 14)'],

        // C#
        'csharp'     => [51,  'C# (Mono 6.6)'],
        'c#'         => [51,  'C# (Mono 6.6)'],
        'cs'         => [51,  'C# (Mono 6.6)'],

        // VB.NET
        'vb'         => [84,  'VB.NET'],
        'vbnet'      => [84,  'VB.NET'],

        // F#
        'fsharp'     => [87,  'F#'],
        'f#'         => [87,  'F#'],

        // Go
        'go'         => [107, 'Go 1.23'],
        'golang'     => [107, 'Go 1.23'],

        // Rust
        'rust'       => [108, 'Rust 1.85'],
        'rs'         => [108, 'Rust 1.85'],

        // Swift
        'swift'      => [83,  'Swift 5.2'],

        // PHP
        'php'        => [98,  'PHP 8.3'],

        // Ruby
        'ruby'       => [72,  'Ruby 2.7'],
        'rb'         => [72,  'Ruby 2.7'],

        // Perl
        'perl'       => [85,  'Perl 5.28'],
        'pl'         => [85,  'Perl 5.28'],

        // Bash
        'bash'       => [46,  'Bash 5.0'],
        'sh'         => [46,  'Bash 5.0'],
        'shell'      => [46,  'Bash 5.0'],

        // Lua
        'lua'        => [64,  'Lua 5.3'],

        // Haskell
        'haskell'    => [61,  'Haskell (GHC 8.8)'],
        'hs'         => [61,  'Haskell (GHC 8.8)'],

        // R
        'r'          => [99,  'R 4.4'],

        // D
        'd'          => [56,  'D (DMD 2.089)'],

        // Elixir
        'elixir'     => [57,  'Elixir 1.9'],
        'ex'         => [57,  'Elixir 1.9'],

        // Erlang
        'erlang'     => [58,  'Erlang OTP 22'],

        // Fortran
        'fortran'    => [59,  'Fortran (GFortran 9)'],

        // OCaml
        'ocaml'      => [65,  'OCaml 4.09'],
        'ml'         => [65,  'OCaml 4.09'],

        // Pascal
        'pascal'     => [67,  'Pascal (FPC 3.0)'],
        'pas'        => [67,  'Pascal (FPC 3.0)'],

        // Prolog
        'prolog'     => [69,  'Prolog (GNU 1.4)'],
        'pl6'        => [69,  'Prolog (GNU 1.4)'],

        // SQL
        'sql'        => [82,  'SQL (SQLite 3.27)'],
        'sqlite'     => [82,  'SQL (SQLite 3.27)'],

        // Dart
        'dart'       => [90,  'Dart 2.19'],

        // Groovy
        'groovy'     => [88,  'Groovy 3.0'],

        // Clojure
        'clojure'    => [86,  'Clojure 1.10'],
        'clj'        => [86,  'Clojure 1.10'],

        // COBOL
        'cobol'      => [77,  'COBOL (GnuCOBOL 2.2)'],

        // Assembly (NASM)
        'asm'        => [45,  'Assembly (NASM 2.14)'],
        'assembly'   => [45,  'Assembly (NASM 2.14)'],
        'nasm'       => [45,  'Assembly (NASM 2.14)'],

        // Common Lisp
        'lisp'       => [55,  'Common Lisp (SBCL 2.0)'],
        'commonlisp' => [55,  'Common Lisp (SBCL 2.0)'],

        // Objective-C
        'objc'       => [79,  'Objective-C (Clang 7)'],
        'objectivec' => [79,  'Objective-C (Clang 7)'],
    ];

    /**
     * Parse teks pesan /run.
     * Format: /run <bahasa>\n<kode>
     * Kembalikan [langId, langLabel, code] atau null jika tidak valid.
     */
    public static function parse(string $fullText): ?array {
        // Hapus prefix /run (dengan atau tanpa @botname)
        $text = preg_replace('/^\/run(?:@\S+)?\s*/iu', '', $fullText);

        if (empty(trim($text))) return null;

        // Baris pertama = nama bahasa, sisanya = kode
        $nlPos = strpos($text, "\n");
        if ($nlPos === false) return null;

        $langRaw = trim(substr($text, 0, $nlPos));
        $code    = substr($text, $nlPos + 1);

        // Hapus markdown code block jika ada (```lang ... ```)
        $code = preg_replace('/^```[\w]*\r?\n?/m', '', $code);
        $code = preg_replace('/```\s*$/m', '', $code);
        $code = trim($code);

        if (empty($code)) return null;

        $langKey = strtolower(trim($langRaw));
        if (!isset(self::$languages[$langKey])) return null;

        [$langId, $langLabel] = self::$languages[$langKey];
        return [$langId, $langLabel, $code];
    }

    /**
     * Preprocessing kode sesuai bahasa:
     * - PHP  : auto-prepend <?php jika belum ada
     * - Erlang: auto-prepend modul wajib jika belum ada
     */
    private static function preprocessCode(int $langId, string $code): string {
        // PHP (id: 68 = PHP 7.4, 98 = PHP 8.3)
        if (in_array($langId, [68, 98])) {
            $trimmed = ltrim($code);
            if (!str_starts_with($trimmed, '<?')) {
                $code = "<?php\n" . $code;
            }
        }

        // Erlang (id: 58) — butuh -module(source). -export([entry_point/0]).
        if ($langId === 58) {
            if (stripos($code, '-module(') === false) {
                $code = "-module(source).\n-export([entry_point/0]).\n\n" . $code;
            }
        }

        return $code;
    }

    /**
     * Kirim kode ke Judge0 CE API dan kembalikan array hasil.
     */
    public static function execute(int $langId, string $code, string $stdin = ''): array {
        $code = self::preprocessCode($langId, $code);

        $body = json_encode([
            'language_id' => $langId,
            'source_code' => $code,
            'stdin'       => $stdin,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || !$response) {
            return ['_error' => Lang::t('run.conn_error', ['err' => ($error ?: 'timeout')])];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['_error' => Lang::t('run.invalid_response', ['code' => $httpCode])];
        }

        return $data;
    }

    /**
     * Format hasil eksekusi menjadi pesan HTML Telegram.
     */
    public static function format(string $langLabel, array $result): string {
        if (isset($result['_error'])) {
            return "❌ <b>Error:</b> " . htmlspecialchars($result['_error']);
        }

        $stdout  = trim((string)($result['stdout']         ?? ''));
        $stderr  = trim((string)($result['stderr']         ?? ''));
        $compile = trim((string)($result['compile_output'] ?? ''));
        $message = trim((string)($result['message']        ?? ''));
        $status  = $result['status']['description']        ?? 'Unknown';
        $time    = $result['time']                         ?? '';
        $memory  = $result['memory']                       ?? '';

        $maxLen = 3000;

        $parts   = [];
        $parts[] = "💻 <b>Run</b> · <code>$langLabel</code>";

        // Status badge
        $statusIcon = match (true) {
            str_contains($status, 'Accepted')    => '✅',
            str_contains($status, 'Error')       => '🔴',
            str_contains($status, 'Time Limit')  => '⏱',
            str_contains($status, 'Memory')      => '💾',
            default                              => '⚠️',
        };
        $parts[] = "$statusIcon <i>" . htmlspecialchars($status) . "</i>";

        // Compile error
        if ($compile !== '') {
            $ce = htmlspecialchars(mb_substr($compile, 0, $maxLen));
            if (mb_strlen($compile) > $maxLen) $ce .= "\n<i>" . Lang::t('run.truncated') . "</i>";
            $parts[] = "\n🔧 <b>Compile Error:</b>\n<pre>$ce</pre>";
        }

        // Standard output
        if ($stdout !== '') {
            $out = htmlspecialchars(mb_substr($stdout, 0, $maxLen));
            if (mb_strlen($stdout) > $maxLen) $out .= "\n<i>" . Lang::t('run.output_truncated') . "</i>";
            $parts[] = "\n📤 <b>Output:</b>\n<pre>$out</pre>";
        }

        // Standard error / runtime error
        if ($stderr !== '') {
            $err = htmlspecialchars(mb_substr($stderr, 0, 1500));
            if (mb_strlen($stderr) > 1500) $err .= "\n<i>" . Lang::t('run.truncated') . "</i>";
            $parts[] = "\n🔴 <b>Stderr:</b>\n<pre>$err</pre>";
        }

        // Extra message from Judge0 (e.g. time limit exceeded)
        if ($message !== '' && $message !== 'null') {
            $parts[] = "\n⚠️ <i>" . htmlspecialchars($message) . "</i>";
        }

        if ($stdout === '' && $stderr === '' && $compile === '') {
            $parts[] = "\n" . Lang::t('run.no_output');
        }

        // Stats
        $statParts = [];
        if ($time !== '' && $time !== null) $statParts[] = "⏱ {$time}s";
        if ($memory !== '' && $memory !== null) $statParts[] = "💾 " . round((int)$memory / 1024, 1) . " MB";
        if ($statParts) $parts[] = "\n<i>" . implode(' · ', $statParts) . "</i>";

        return implode("\n", $parts);
    }

    /**
     * Pesan bantuan /run — diambil dari sistem lang aktif.
     */
    public static function helpText(): string {
        return Lang::t('run.help');
    }
}
